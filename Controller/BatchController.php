<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ProcessAndInvoice\Controller;

use ProcessAndInvoice\ProcessAndInvoice;
use ProcessAndInvoice\Service\BatchException;
use ProcessAndInvoice\Service\BatchFailure;
use ProcessAndInvoice\Service\BatchProcessor;
use ProcessAndInvoice\Service\CompletionResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Exception\TokenAuthenticationException;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Tools\TokenProvider;

/**
 * The steps of the "Process paid orders" button of the order list: open (or resume) a
 * batch, process it ten orders at a time, complete it (or close it when it cannot go on),
 * download its document. Every step
 * that changes something is a POST carrying the back-office token.
 */
#[Route('/admin/module/ProcessAndInvoice/batch', name: 'process_and_invoice.batch.')]
class BatchController extends BaseAdminController
{
    #[Route('', name: 'open', methods: ['POST'])]
    public function open(BatchProcessor $processor, TokenProvider $tokenProvider, LoggerInterface $logger): Response
    {
        if (null !== $denied = $this->guard($tokenProvider)) {
            return $denied;
        }

        return $this->answer(function () use ($processor): JsonResponse {
            $state = $processor->open($this->getSecurityContext()->getAdminUser()?->getId());

            if (null === $state) {
                return new JsonResponse([
                    'nothingToProcess' => true,
                    'message' => $this->translator->trans('No paid order to process.', [], ProcessAndInvoice::DOMAIN_NAME),
                ]);
            }

            return new JsonResponse([
                'batchId' => $state->batch->id,
                'orderCount' => $state->orderCount,
                'pendingCount' => $state->pendingCount,
                'resumed' => $state->resumed,
            ]);
        }, $logger);
    }

    #[Route('/{batchId}/step', name: 'step', methods: ['POST'], requirements: ['batchId' => '\d+'])]
    public function step(int $batchId, BatchProcessor $processor, TokenProvider $tokenProvider, LoggerInterface $logger): Response
    {
        if (null !== $denied = $this->guard($tokenProvider)) {
            return $denied;
        }

        return $this->answer(function () use ($processor, $batchId): JsonResponse {
            $result = $processor->processNext($batchId);

            return new JsonResponse([
                'processedCount' => \count($result->processedOrderIds),
                'failures' => $this->describeFailures($result->failures),
                'pendingCount' => $result->pendingCount,
            ]);
        }, $logger);
    }

    #[Route('/{batchId}/complete', name: 'complete', methods: ['POST'], requirements: ['batchId' => '\d+'])]
    public function complete(int $batchId, BatchProcessor $processor, TokenProvider $tokenProvider, UrlGeneratorInterface $urlGenerator, LoggerInterface $logger): Response
    {
        if (null !== $denied = $this->guard($tokenProvider)) {
            return $denied;
        }

        return $this->answer(function () use ($processor, $batchId, $urlGenerator): JsonResponse {
            return $this->completionResponse($processor->complete($batchId), $urlGenerator);
        }, $logger);
    }

    /**
     * The way out of a batch that cannot go on: the orders still pending keep their status.
     */
    #[Route('/{batchId}/close', name: 'close', methods: ['POST'], requirements: ['batchId' => '\d+'])]
    public function close(int $batchId, BatchProcessor $processor, TokenProvider $tokenProvider, UrlGeneratorInterface $urlGenerator, LoggerInterface $logger): Response
    {
        if (null !== $denied = $this->guard($tokenProvider)) {
            return $denied;
        }

        return $this->answer(fn (): JsonResponse => $this->completionResponse($processor->close($batchId), $urlGenerator), $logger);
    }

    #[Route('/{batchId}/download', name: 'download', methods: ['GET'], requirements: ['batchId' => '\d+'])]
    public function download(int $batchId, BatchProcessor $processor): Response
    {
        if (null !== $denied = $this->checkAuth(AdminResources::ORDER, [], AccessManager::VIEW)) {
            return $denied;
        }

        $documentPath = $processor->documentPath($batchId);
        $completedAt = $processor->batch($batchId)?->completedAt;

        if (null === $documentPath || null === $completedAt) {
            return new Response($this->translator->trans(BatchException::NOT_FOUND, [], ProcessAndInvoice::DOMAIN_NAME), Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($documentPath);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'ordersInvoice_'.$completedAt->format('Y-m-d_H-i-s').'.pdf');

        return $response;
    }

    /**
     * Changing order statuses is an order update: the order right is checked, then the token.
     */
    private function guard(TokenProvider $tokenProvider): ?Response
    {
        if (null !== $denied = $this->checkAuth(AdminResources::ORDER, [], AccessManager::UPDATE)) {
            return $denied;
        }

        try {
            $tokenProvider->checkToken((string) $this->getRequest()->request->get('_token', ''));
        } catch (TokenAuthenticationException) {
            return $this->error('Your session has expired, please reload the page and try again', Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    /**
     * @param callable(): JsonResponse $action
     */
    private function answer(callable $action, LoggerInterface $logger): JsonResponse
    {
        try {
            return $action();
        } catch (BatchException $exception) {
            return $this->error($exception->getMessage(), BatchException::NOT_FOUND === $exception->getMessage() ? Response::HTTP_NOT_FOUND : Response::HTTP_CONFLICT);
        } catch (\Throwable $exception) {
            // The raw message may carry SQL or paths: it is logged, the administrator gets a generic one.
            $logger->error('ProcessAndInvoice: {message}', ['message' => $exception->getMessage(), 'exception' => $exception]);

            return $this->error('An unexpected error occurred, the processing stopped. Click the button again to resume it.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function completionResponse(CompletionResult $result, UrlGeneratorInterface $urlGenerator): JsonResponse
    {
        return new JsonResponse([
            'processedCount' => $result->processedCount,
            'failures' => $this->describeFailures($result->failures),
            'downloadUrl' => null !== $result->documentPath
                ? $urlGenerator->generate('process_and_invoice.batch.download', ['batchId' => $result->batch->id])
                : null,
        ]);
    }

    private function error(string $translationKey, int $status): JsonResponse
    {
        return new JsonResponse(['message' => $this->translator->trans($translationKey, [], ProcessAndInvoice::DOMAIN_NAME)], $status);
    }

    /**
     * @param list<BatchFailure> $failures
     *
     * @return list<array{reference: string, reason: string}>
     */
    private function describeFailures(array $failures): array
    {
        return array_map(fn (BatchFailure $failure): array => [
            'reference' => $failure->reference,
            'reason' => $this->translator->trans(match ($failure->reason) {
                BatchProcessor::FAILURE_STATUS_CHANGED => 'No longer paid when its turn came',
                BatchProcessor::FAILURE_INVOICE => 'The invoice could not be printed',
                BatchProcessor::FAILURE_TRANSITION_REFUSED => 'The order status transitions do not allow the move to processing',
                BatchProcessor::FAILURE_BATCH_CLOSED => 'The batch was closed before its turn',
                default => 'The status change failed',
            }, [], ProcessAndInvoice::DOMAIN_NAME),
        ], $failures);
    }
}
