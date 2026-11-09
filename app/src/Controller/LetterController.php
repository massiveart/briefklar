<?php

declare(strict_types=1);

namespace App\Controller;

use App\Explanation\ExplanationFailedException;
use App\Explanation\ExplanationStyle;
use App\Explanation\LetterExplainer;
use App\Extraction\ExtractionChain;
use App\Extraction\ExtractionFailedException;
use App\Extraction\UnsupportedDocumentException;
use App\Form\LetterUpload;
use App\Form\LetterUploadType;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class LetterController extends AbstractController
{
    public function __construct(
        private readonly ExtractionChain $extraction,
        private readonly LetterExplainer $explainer,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The upload is processed within this request only. PHP deletes the temporary upload file
     * when the request ends; nothing is written anywhere else (see docs/tasks/B-briefklar-app.md).
     */
    #[Route('/', name: 'letter_upload', methods: ['GET', 'POST'])]
    public function upload(Request $request): Response
    {
        $upload = new LetterUpload();
        $form = $this->createForm(LetterUploadType::class, $upload);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && null !== $upload->letter) {
            $file = $upload->letter;

            try {
                $extracted = $this->extraction->extract($file->getPathname(), (string) $file->getMimeType());
                $explained = $this->explainer->explain(
                    $extracted,
                    $upload->easyLanguage ? ExplanationStyle::EasyLanguage : ExplanationStyle::Standard,
                );

                return $this->render('letter/result.html.twig', ['result' => $explained]);
            } catch (UnsupportedDocumentException|ExtractionFailedException $e) {
                // Log the reason, never the content.
                $this->logger->warning('Letter extraction failed: {reason}', ['reason' => $e->getMessage(), 'mime' => $file->getMimeType()]);
                $form->get('letter')->addError(new FormError($this->translator->trans('letter.upload.unreadable', domain: 'validators')));
            } catch (ExplanationFailedException $e) {
                $this->logger->error('Letter explanation failed: {reason}', ['reason' => $e->getMessage(), 'cause' => null === $e->getPrevious() ? null : $e->getPrevious()::class]);
                $form->addError(new FormError($this->translator->trans('letter.explanation.failed', domain: 'validators')));
            }
        }

        return $this->render('letter/upload.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? 422 : 200));
    }
}
