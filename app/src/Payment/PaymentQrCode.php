<?php

declare(strict_types=1);

namespace App\Payment;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;

/**
 * Renders an EPC payment as inline SVG, on this server; no QR service ever sees the payment data.
 *
 * EPC069-12 asks for error correction level M and at most QR version 13. The payload declares
 * UTF-8 itself (character set 1), so the bytes go in without an ECI header, which some banking
 * app scanners do not read.
 */
final class PaymentQrCode
{
    private const int MAX_VERSION = 13;
    private const int SIZE_PX = 240;

    public function svg(PaymentDetails $payment): string
    {
        $qr = Encoder::encode(EpcPayload::of($payment), ErrorCorrectionLevel::M(), 'UTF-8', prefixEci: false);
        if ($qr->getVersion()->getVersionNumber() > self::MAX_VERSION) {
            throw new \InvalidArgumentException('EPC QR code exceeds version 13.');
        }

        $svg = (new ImageRenderer(new RendererStyle(self::SIZE_PX), new SvgImageBackEnd()))->render($qr);

        // Inline in the page: drop the XML declaration.
        return trim((string) preg_replace('/^<\?xml[^>]*>\s*/', '', $svg));
    }
}
