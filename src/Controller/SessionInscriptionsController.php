<?php

namespace App\Controller;

use App\Report\SessionInscriptionsReport;
use App\Repository\SessionRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
class SessionInscriptionsController
{
    public function __construct(
        private readonly SessionRepository $sessionRepository,
        private readonly SessionInscriptionsReport $report,
    ) {
    }

    /**
     * {id} is the session id of the form API (e.g. "ses_03"): the one the operations team knows.
     */
    #[Route('/api/sessions/{id}/inscriptions', name: 'api_session_inscriptions', methods: ['GET'])]
    public function __invoke(string $id): JsonResponse
    {
        $session = $this->sessionRepository->findOneBy(['externalId' => $id]);
        if (null === $session) {
            return $this->json(
                ['error' => 'session_not_found', 'message' => sprintf('No session with id %s. Has the import been run?', $id)],
                Response::HTTP_NOT_FOUND,
            );
        }

        return $this->json($this->report->build($session));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        // Readable accents ("Bourg-en-Bresse — 17 novembre") instead of \u escapes.
        $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        // Some clients (e.g. Windows PowerShell) assume Latin-1 when no charset is given.
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');

        return $response;
    }
}
