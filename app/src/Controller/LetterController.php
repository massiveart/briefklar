<?php

declare(strict_types=1);

namespace App\Controller;

use App\Explanation\ExplainedLetter;
use App\Explanation\ExplanationFailedException;
use App\Explanation\ExplanationLanguage;
use App\Explanation\ExplanationStyle;
use App\Explanation\LetterExplainer;
use App\Explanation\ProviderSetup;
use App\Extraction\ExtractionChain;
use App\Extraction\ExtractionFailedException;
use App\Extraction\UnsupportedDocumentException;
use App\Form\LetterUpload;
use App\Form\LetterUploadType;
use App\Payment\PaymentQrCode;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\EnumRequirement;
use Symfony\Contracts\Translation\TranslatorInterface;

final class LetterController extends AbstractController
{
    /** Headroom on top of the model timeout for OCR of a multi-page scan and rendering. */
    private const int EXTRACTION_HEADROOM_SECONDS = 60;

    public function __construct(
        private readonly ExtractionChain $extraction,
        private readonly LetterExplainer $explainer,
        private readonly ProviderSetup $providerSetup,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        private readonly PaymentQrCode $paymentQr,
        #[Autowire('%env(int:EXPLANATION_TIMEOUT)%')]
        private readonly int $explanationTimeout,
    ) {
    }

    #[Route('/', name: 'home', methods: ['GET'])]
    public function home(Request $request): RedirectResponse
    {
        $locale = $request->getPreferredLanguage(ExplanationLanguage::locales()) ?? ExplanationLanguage::German->value;

        return $this->redirectToRoute('letter_upload', ['_locale' => $locale]);
    }

    /**
     * The upload is processed within this request only. PHP deletes the temporary upload file
     * when the request ends; nothing is written anywhere else (see docs/tasks/B-briefklar-app.md).
     */
    #[Route('/{_locale}', name: 'letter_upload', requirements: ['_locale' => new EnumRequirement(ExplanationLanguage::class)], methods: ['GET', 'POST'])]
    public function upload(Request $request): Response
    {
        $upload = new LetterUpload();
        $form = $this->createForm(LetterUploadType::class, $upload);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && null !== $upload->letter) {
            $file = $upload->letter;
            // PHP's default of 30 s would cut off a local model mid-answer; the HTTP client
            // timeout (framework.yaml) ends a hanging model call first, with a proper error.
            // Up to two model calls: detection (PII_MODEL_DETECTION) and explanation.
            set_time_limit(2 * $this->explanationTimeout + self::EXTRACTION_HEADROOM_SECONDS);

            try {
                $extracted = $this->extraction->extract($file->getPathname(), (string) $file->getMimeType());
                $explained = $this->explainer->explain(
                    $extracted,
                    $upload->easyLanguage ? ExplanationStyle::EasyLanguage : ExplanationStyle::Standard,
                    ExplanationLanguage::from($request->getLocale()),
                );

                return $this->render('letter/result.html.twig', [
                    'result' => $explained,
                    'payment_qr' => $this->paymentQrSvg($explained),
                ]);
            } catch (UnsupportedDocumentException|ExtractionFailedException $e) {
                // Log the reason, never the content.
                $this->logger->warning('Letter extraction failed: {reason}', ['reason' => $e->getMessage(), 'mime' => $file->getMimeType()]);
                $form->get('letter')->addError(new FormError($this->translator->trans('letter.upload.unreadable', domain: 'validators')));
            } catch (ExplanationFailedException $e) {
                $this->logger->error('Letter explanation failed: {reason}', ['reason' => $e->getMessage(), 'cause' => null === $e->getPrevious() ? null : $e->getPrevious()::class]);
                $form->addError(new FormError($this->translator->trans('letter.explanation.failed', domain: 'validators')));
            }
        }

        return $this->render('letter/upload.html.twig', [
            'form' => $form,
            'missing_provider_key' => $this->providerSetup->missingKey(),
        ], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    private function paymentQrSvg(ExplainedLetter $explained): ?string
    {
        if (null === $explained->payment) {
            return null;
        }

        try {
            return $this->paymentQr->svg($explained->payment);
        } catch (\InvalidArgumentException $e) {
            // Does not fit the EPC limits: no code rather than a wrong one. No payment data in the log.
            $this->logger->notice('Payment QR code skipped: {reason}', ['reason' => $e->getMessage()]);

            return null;
        }
    }
}
