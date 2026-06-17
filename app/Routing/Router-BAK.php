<?php
declare(strict_types=1);

namespace App\Routing;

use App\Auth\AuthContext;
use App\Controllers\AdminEvidenceAccessController;
use App\Controllers\AdminEvidenceAuditController;
use App\Controllers\AdminExportsGovernanceController;
use App\Controllers\CaseActionRequestsController;
use App\Controllers\DemandDeliveryTasksController;
use App\Controllers\CaseCapabilitiesController;
use App\Controllers\CaseDocumentAuditController;
use App\Controllers\CaseDocumentExportsController;
use App\Controllers\CaseDocumentsController;
use App\Controllers\CaseIngressController;
use App\Controllers\CasesController;
use App\Controllers\CasesTimelineController;
use App\Controllers\CourtsController;
use App\Controllers\DocumentsController;
use App\Controllers\ExportAccessGrantsController;
use App\Controllers\ExternalEvidenceController;
use App\Controllers\InternalEvidenceJobsController;
use App\Controllers\OwnershipAdjudicationController;
use App\Controllers\ProceduralDeadlinesController;
use App\Controllers\ProceduralStateController;
use App\Controllers\ProcessServiceEventsController;
use App\Controllers\PropertiesController;
use App\Controllers\PropertyCasesController;
use App\Controllers\PropertyRelationshipsController;
use App\Controllers\StorageController;
use App\Controllers\TimelinePolicyController;
use App\Controllers\EligibleActionsController;
use App\Controllers\CasePrerequisitesController;
use App\Controllers\FilingIntentController;
use App\Controllers\FilingArtifactsController;
use App\Controllers\AdminJurisdictionBoundariesController;
use Firebase\JWT\JWT;
use PDO;
use RuntimeException;

final class Router
{
    public function __construct(
        private PDO $db
    ) {}

    /* ============================================================
     * PREPARE (canonical endpoint resolution)
     * ============================================================ */
    public function prepare(array &$req): void
    {
        $method = strtoupper($req['method'] ?? $_SERVER['REQUEST_METHOD'] ?? 'GET');
        $rawUri = $req['uri'] ?? $_SERVER['REQUEST_URI'] ?? '/';
        $path   = parse_url($rawUri, PHP_URL_PATH) ?? '/';

        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        $req['method'] = $method;
        $req['path']   = $path;
        $req['params'] = [];
        $req['query']  = $req['query'] ?? $_GET;

        /* ============================================================
         * Multipart + body hydration
         * ============================================================ */
        if (!empty($_FILES)) {
            $req['files'] = $_FILES;
        }

        if (!empty($_POST)) {
            $req['body'] = $_POST;
        } else {
            $raw = file_get_contents('php://input');
            if (is_string($raw) && $raw !== '') {
                $json = json_decode($raw, true);
                if (is_array($json)) {
                    $req['body'] = $json;
                }
            }
        }

        unset($req['case_id'], $req['case_document_id']);

        $endpoint = $method . ' ' . $path;

        /* ============================================================
         * Core / Identity
         * ============================================================ */
        if ($path === '/health') {
            $endpoint = 'GET /health';
        } elseif ($path === '/session') {
            $endpoint = 'GET /session';
        } elseif ($path === '/me') {
            $endpoint = 'GET /me';
        } elseif ($path === '/auth/login') {
            $endpoint = 'POST /auth/login';
        }

        /* ============================================================
         * Properties (pattern table — first match wins)
         * ============================================================ */
        $propertyRoutes = [
            // Ownership adjudication (MOST SPECIFIC FIRST)
            [
                'method' => 'GET',
                'regex'  => '#^/properties/([0-9a-f-]{36})/ownership/validate$#i',
                'apply'  => function ($m) use (&$req) {
                    $req['params']['id'] = $m[1];
                    return 'GET /properties/{id}/ownership/validate';
                },
            ],
            [
                'method' => 'POST',
                'regex'  => '#^/properties/([0-9a-f-]{36})/ownership/adjudicate$#i',
                'apply'  => function ($m) use (&$req) {
                    $req['params']['id'] = $m[1];
                    return 'POST /properties/{id}/ownership/adjudicate';
                },
            ],

            // Property collection
            [
                'method' => null,
                'regex'  => '#^/properties$#',
                'apply'  => function () use ($method) {
                    return $method . ' /properties';
                },
            ],

            // Property relationships
            [
                'method' => 'GET',
                'regex'  => '#^/properties/([0-9a-f-]{36})/relationships$#i',
                'apply'  => function ($m) use (&$req) {
                    $req['params']['id'] = $m[1];
                    return 'GET /properties/{id}/relationships';
                },
            ],
            [
                'method' => 'POST',
                'regex'  => '#^/properties/([0-9a-f-]{36})/relationships$#i',
                'apply'  => function ($m) use (&$req) {
                    $req['params']['id'] = $m[1];
                    return 'POST /properties/{id}/relationships';
                },
            ],
            [
                'method' => 'PATCH',
                'regex'  => '#^/properties/([0-9a-f-]{36})/relationships/([0-9a-f-]{36})$#i',
                'apply'  => function ($m) use (&$req) {
                    $req['params']['id'] = $m[1];
                    $req['params']['relationship_id'] = $m[2];
                    return 'PATCH /properties/{id}/relationships/{relationship_id}';
                },
            ],

            // Property images
            [
                'method' => 'GET',
                'regex'  => '#^/properties/([0-9a-f-]{36})/images$#i',
                'apply'  => function ($m) use (&$req) {
                    $req['params']['id'] = $m[1];
                    return 'GET /properties/{id}/images';
                },
            ],

            // Property documents
            [
                'method' => 'GET',
                'regex'  => '#^/properties/([0-9a-f-]{36})/documents$#i',
                'apply'  => function ($m) use (&$req) {
                    $req['params']['id'] = $m[1];
                    return 'GET /properties/{id}/documents';
                },
            ],

            // Property cases
            [
                'method' => 'GET',
                'regex'  => '#^/properties/([0-9a-f-]{36})/cases$#i',
                'apply'  => function ($m) use (&$req) {
                    $req['params']['id'] = $m[1];
                    return 'GET /properties/{id}/cases';
                },
            ],

            // Property PATCH — must come before generic GET
            [
                'method' => 'PATCH',
                'regex'  => '#^/properties/([0-9a-f-]{36})$#i',
                'apply'  => function ($m) use (&$req) {
                    $req['params']['id'] = $m[1];
                    return 'PATCH /properties/{id}';
                },
            ],

            // Generic property GET — MUST BE LAST
            [
                'method' => 'GET',
                'regex'  => '#^/properties/([0-9a-f-]{36})$#i',
                'apply'  => function ($m) use (&$req) {
                    $req['params']['id'] = $m[1];
                    return 'GET /properties/{id}';
                },
            ],
        ];

        foreach ($propertyRoutes as $route) {
            if ($route['method'] !== null && $route['method'] !== $method) {
                continue;
            }
            if (preg_match($route['regex'], $path, $m)) {
                $req['canonical_endpoint'] = $route['apply']($m);
                return; // FIRST MATCH WINS for /properties/*
            }
        }

        /* ============================================================
         * Documents
         * ============================================================ */
        if ($path === '/documents/property-images') {
            $endpoint = 'POST /documents/property-images';
        } elseif (preg_match('#^/documents/([0-9a-f-]{36})/binary$#i', $path, $m)) {
            $req['params']['id'] = $m[1];
            $endpoint = 'GET /documents/{id}/binary';
        }

        // Property image binary / thumbnail / delete
        if (preg_match('#^/property-images/([0-9a-f-]{36})/binary$#i', $path, $m)) {
            $req['params']['id'] = $m[1];
            $endpoint = 'GET /property-images/{id}/binary';
        } elseif (preg_match('#^/property-images/([0-9a-f-]{36})/thumbnail$#i', $path, $m)) {
            $req['params']['id'] = $m[1];
            $endpoint = 'GET /property-images/{id}/thumbnail';
        } elseif (preg_match('#^/property-images/([0-9a-f-]{36})$#i', $path, $m) && $method === 'DELETE') {
            $req['params']['id'] = $m[1];
            $endpoint = 'DELETE /property-images/{id}';
        }

        /* ============================================================
         * Cases
         * ============================================================ */
        if ($path === '/cases') {
            if ($method === 'POST') {
                $endpoint = 'POST /cases';
            }
        } elseif (preg_match('#^/cases/([0-9a-f-]{36})$#i', $path, $m)) {
            $req['params']['id'] = $req['case_id'] = $m[1];
            $endpoint = $method === 'PATCH'
                ? 'PATCH /cases/{id}'
                : 'GET /cases/{id}';
        } elseif (preg_match('#^/cases/([0-9a-f-]{36})/documents$#i', $path, $m)) {
            $req['params']['id'] = $req['case_id'] = $m[1];
            $endpoint = $method === 'POST'
                ? 'POST /cases/{id}/documents'
                : 'GET /cases/{id}/documents';
        } elseif (preg_match('#^/cases/([0-9a-f-]{36})/capabilities$#i', $path, $m)) {
            $req['params']['id'] = $req['case_id'] = $m[1];
            $endpoint = $method === 'POST'
                ? 'POST /cases/{id}/capabilities'
                : 'GET /cases/{id}/capabilities';
        } elseif (preg_match('#^/cases/([0-9a-f-]{36})/capabilities/([^/]+)$#i', $path, $m)) {
            $req['params']['id'] = $req['case_id'] = $m[1];
            $req['params']['capability'] = $m[2];
            $endpoint = 'DELETE /cases/{id}/capabilities/{capability}';
        }

        /* ============================================================
         * Case ingress
         * ============================================================ */
        if (preg_match('#^/cases/([0-9a-f-]{36})/ingress$#i', $path, $m)) {
            $req['params']['id'] = $req['case_id'] = $m[1];
            $endpoint = $method === 'POST'
                ? 'POST /cases/{id}/ingress'
                : 'GET /cases/{id}/ingress';
        } elseif (preg_match('#^/cases/([0-9a-f-]{36})/ingress/([0-9a-f-]{36})$#i', $path, $m)) {
            $req['params']['id'] = $req['case_id'] = $m[1];
            $req['params']['submission_id'] = $m[2];
            $endpoint = 'GET /cases/{id}/ingress/{submission_id}';
        } elseif (preg_match('#^/cases/([0-9a-f-]{36})/ingress/([0-9a-f-]{36})/(accept|reject)$#i', $path, $m)) {
            $req['params']['id'] = $req['case_id'] = $m[1];
            $req['params']['submission_id'] = $m[2];
            $endpoint = 'POST /cases/{id}/ingress/{submission_id}/' . $m[3];
        }

        /* ============================================================
         * Timeline (Phase 14.1)
         * ============================================================ */
        if (preg_match('#^/cases/([0-9a-f-]{36})/timeline$#i', $path, $m) && $method === 'GET') {
            $req['params']['case_id'] = $m[1];
            $endpoint = 'GET /cases/{case_id}/timeline';
        }

        /* ============================================================
         * Tenant timeline policy (Phase 14.2)
         * ============================================================ */
        if ($path === '/admin/timeline-policy' && $method === 'GET') {
            $endpoint = 'GET /admin/timeline-policy';
        } elseif ($path === '/admin/timeline-policy' && $method === 'PUT') {
            $endpoint = 'PUT /admin/timeline-policy';
        }


        /* ============================================================
         * Legal action requests
         * ============================================================ */
        if (preg_match('#^/cases/([0-9a-f-]{36})/action-requests$#i', $path, $m)) {
            $req['params']['id'] = $req['case_id'] = $m[1];
            $endpoint = $method === 'POST'
                ? 'POST /cases/{id}/action-requests'
                : 'GET /cases/{id}/action-requests';
        } elseif (preg_match('#^/action-requests/([0-9a-f-]{36})/execute$#i', $path, $m) && $method === 'POST') {
            $req['params']['request_id'] = $m[1];
            $endpoint = 'POST /action-requests/{request_id}/execute';
        } elseif (preg_match('#^/action-requests/([0-9a-f-]{36})$#i', $path, $m) && $method === 'PATCH') {
            $req['params']['request_id'] = $m[1];
            $endpoint = 'PATCH /action-requests/{request_id}';
        }

        // ------------------------------------------------------------
        // GET /cases/{id}/eligible-actions (Phase 14.3)
        // ------------------------------------------------------------
        if (preg_match('#^/cases/([0-9a-f-]{36})/eligible-actions$#i', $path, $m) && $method === 'GET') {
            $req['params']['id'] = $req['case_id'] = $m[1];
            $endpoint = 'GET /cases/{id}/eligible-actions';
        }

        // ------------------------------------------------------------
        // POST /cases/{id}/prerequisites/{action_type}/satisfy (Phase 14.3)
        // ------------------------------------------------------------
        if (preg_match('#^/cases/([0-9a-f-]{36})/prerequisites/([A-Za-z0-9_:\\-]{3,100})/satisfy$#', $path, $m) && $method === 'POST') {
            $req['params']['id'] = $req['case_id'] = $m[1];
            $req['params']['action_type'] = $m[2];
            $endpoint = 'POST /cases/{id}/prerequisites/{action_type}/satisfy';
        }

        // Case action request targets
        if (preg_match('#^/cases/([0-9a-f-]{36})/action-targets$#i', $path, $m)) {
            $req['params']['id'] = $req['case_id'] = $m[1];
            $endpoint = $method === 'POST'
                ? 'POST /cases/{id}/action-targets'
                : 'GET /cases/{id}/action-targets';
        }


        /* ============================================================
         * Procedural deadlines (Phase 14.2)
         * ============================================================ */
        if ($path === '/procedural-deadlines' && $method === 'POST') {
            $endpoint = 'POST /procedural-deadlines';
        } elseif ($path === '/procedural-deadlines/ingest' && $method === 'POST') {
            $endpoint = 'POST /procedural-deadlines/ingest';
        } elseif (preg_match('#^/procedural-deadlines/([0-9a-f-]{36})/satisfy$#i', $path, $m) && $method === 'POST') {
            $req['params']['id'] = $m[1];
            $endpoint = 'POST /procedural-deadlines/{id}/satisfy';
        } elseif (preg_match('#^/procedural-deadlines/([0-9a-f-]{36})$#i', $path, $m) && $method === 'PATCH') {
            $req['params']['id'] = $m[1];
            $endpoint = 'PATCH /procedural-deadlines/{id}';
        }

        /* ============================================================
        * Demand / Service Tasks (Phase 14.4)
        * ============================================================ */
        if (preg_match('#^/cases/([0-9a-f-]{36})/service-tasks$#i', $path, $m)) {
            $req['params']['id'] = $m[1];
            $endpoint = 'GET /cases/{id}/service-tasks';

        } elseif (preg_match('#^/service-tasks/([0-9a-f-]{36})$#i', $path, $m) && $method === 'PATCH') {
            $req['params']['id'] = $m[1];
            $endpoint = 'PATCH /service-tasks/{id}';
        }

        /* ============================================================
        * Case Parties (Phase 14.5)
        * ============================================================ */

        // GET /cases/{id}/parties
        if (preg_match('#^/cases/([0-9a-f-]{36})/parties$#i', $path, $m) && $method === 'GET') {
            $req['params']['id'] = $m[1];
            $endpoint = 'GET /cases/{id}/parties';

        // POST /cases/{id}/parties
        } elseif (preg_match('#^/cases/([0-9a-f-]{36})/parties$#i', $path, $m) && $method === 'POST') {
            $req['params']['id'] = $m[1];
            $endpoint = 'POST /cases/{id}/parties';

        // DELETE /cases/{id}/parties/{actor_id}/{party_role}
        } elseif (
            preg_match(
                '#^/cases/([0-9a-f-]{36})/parties/([0-9a-f-]{36})/([a-z_]+)$#i',
                $path,
                $m
            )
            && $method === 'DELETE'
        ) {
            $req['params']['id']        = $m[1]; // case_id
            $req['params']['actor_id']  = $m[2];
            $req['params']['party_role']= strtolower($m[3]);
            $endpoint = 'DELETE /cases/{id}/parties/{actor_id}/{party_role}';
        }

        // POST /properties/{id}/cases
        if (preg_match('#^/properties/([0-9a-f-]{36})/cases$#i', $path, $m) && $method === 'POST') {
            $req['params']['id'] = $m[1];
            $endpoint = 'POST /properties/{id}/cases';
        }

        // ------------------------------------------------------------
        // GET /cases/{case_id}/procedural-state
        // ------------------------------------------------------------
        if (preg_match('#^/cases/([0-9a-f-]{36})/procedural-state$#i', $path, $m)) {
            $req['params']['id'] = $req['case_id'] = $m[1];

            $endpoint = $method === 'GET'
                ? 'GET /cases/{id}/procedural-state'
                : $endpoint;
        }

        /* ============================================================
         * legal_actions section
         * ============================================================ */
        if (preg_match(
                '#^/cases/([0-9a-f-]{36})/actions/([a-z0-9_]+)/initiate$#i',
                $path,
                $m
            )) {
                $req['params']['id'] = $req['case_id'] = $m[1];
                $req['params']['action_type'] = $m[2];

                $endpoint = 'POST /cases/{id}/actions/{action_type}/initiate';
            }

            if (preg_match('#^/cases/([0-9a-f-]{36})/legal-actions$#i', $path, $m)) {
                $req['params']['id'] = $req['case_id'] = $m[1];

                $endpoint = 'GET /cases/{id}/legal-actions';
            }

        // ------------------------------------------------------------
        // POST /cases/{case_id}/legal-actions/{legal_action_id}/court
        // ------------------------------------------------------------
        if (
            preg_match(
                '#^/cases/([0-9a-f-]{36})/legal-actions/([0-9a-f-]{36})/court$#i',
                $path,
                $m
            ) && $method === 'POST'
        ) {
            $req['params']['case_id'] = $req['case_id'] = $m[1];
            $req['params']['legal_action_id'] = $m[2];
            $endpoint = 'POST /cases/{case_id}/legal-actions/{legal_action_id}/court';
        }

        // ------------------------------------------------------------
        // GET /cases/{id}/legal-actions/{legal_action_id}/filing-readiness (Phase 16.4)
        // ------------------------------------------------------------
        if (preg_match('#^/cases/([0-9a-f-]{36})/legal-actions/([0-9a-f-]{36})/filing-readiness$#i', $path, $m) && $method === 'GET') {
            $req['params']['id'] = $req['case_id'] = $m[1];
            $req['params']['legal_action_id'] = $m[2];
            $endpoint = 'GET /cases/{id}/legal-actions/{legal_action_id}/filing-readiness';
        }

        if (preg_match(
            '#^/cases/([0-9a-f-]{36})/legal-actions/([0-9a-f-]{36})/filing-intent$#i',
            $path,
            $m
        )) {
            $req['params']['case_id'] = $m[1];
            $req['params']['legal_action_id'] = $m[2];

            if ($method === 'GET') {
                $endpoint = 'GET /cases/{case_id}/legal-actions/{legal_action_id}/filing-intent';
            } else {
                $endpoint = 'METHOD_NOT_ALLOWED';
                $allowedMethods = ['GET'];
            }
        }

        if (preg_match(
            '#^/cases/([0-9a-f-]{36})/legal-actions/([0-9a-f-]{36})/filing-artifacts$#i',
            $path,
            $m
        )) {
            $req['params']['case_id'] = $m[1];
            $req['params']['legal_action_id'] = $m[2];

            if ($method === 'GET') {
                $endpoint = 'GET /cases/{case_id}/legal-actions/{legal_action_id}/filing-artifacts';
            } else {
                $endpoint = 'METHOD_NOT_ALLOWED';
                $allowedMethods = ['GET'];
            }
        }


        /* ============================================================
         * Service of process
         * ============================================================ */
        
        if (preg_match('#^/cases/([0-9a-f-]{36})/process-service-events$#i', $path, $m)) {
            $req['params']['id'] = $m[1];
            $endpoint = $method === 'POST'
                ? 'POST /cases/{id}/process-service-events'
                : 'GET /cases/{id}/process-service-events';
        } elseif (preg_match('#^/process-service-events/([0-9a-f-]{36})$#i', $path, $m) && $method === 'GET') {
            $req['params']['event_id'] = $m[1];
            $endpoint = 'GET /process-service-events/{event_id}';
        } elseif (preg_match('#^/process-service-events/([0-9a-f-]{36})/evidence$#i', $path, $m) && $method === 'POST') {
            $req['params']['event_id'] = $m[1];
            $endpoint = 'POST /process-service-events/{event_id}/evidence';
        }
 
        // Service tasks – certified mail actual delivery (Phase 14.7)
        if (preg_match('#^/service-tasks/([0-9a-f-]{36})/certified-mail/delivered$#i', $path, $m)) {
            $req['params']['id'] = $m[1];
            $endpoint = 'POST /service-tasks/{id}/certified-mail/delivered';
        }

        // Admin: replay case events (Phase 14.8)
        if (preg_match('#^/admin/cases/([0-9a-f-]{36})/replay-events$#i', $path, $m) && $method === 'POST') {
            $endpoint = 'POST /admin/cases/{id}/replay-events';
            $req['params']['id'] = $m[1]; // <-- REQUIRED
        }


        /* ============================================================
         * Case documents
         * ============================================================ */
        if (preg_match('#^/case-documents/([0-9a-f-]{36})/versions$#i', $path, $m)) {
            $req['params']['id'] = $req['case_document_id'] = $m[1];
            $this->hydrateCaseId($req, $m[1]);
            $endpoint = $method . ' /case-documents/{id}/versions';
        } elseif (preg_match('#^/case-documents/([0-9a-f-]{36})/current-version$#i', $path, $m)) {
            $req['params']['id'] = $req['case_document_id'] = $m[1];
            $this->hydrateCaseId($req, $m[1]);
            $endpoint = 'GET /case-documents/{id}/current-version';
        } elseif (preg_match('#^/case-documents/([0-9a-f-]{36})/finalize$#i', $path, $m) && $method === 'POST') {
            $req['params']['id'] = $req['case_document_id'] = $m[1];
            $this->hydrateCaseId($req, $m[1]);
            $endpoint = 'POST /case-documents/{id}/finalize';
        } elseif (preg_match('#^/case-documents/([0-9a-f-]{36})/storage-bind$#i', $path, $m) && $method === 'POST') {
            $req['params']['id'] = $req['case_document_id'] = $m[1];
            $this->hydrateCaseId($req, $m[1]);
            $endpoint = 'POST /case-documents/{id}/storage-bind';
        } elseif (preg_match('#^/case-documents/([0-9a-f-]{36})/storage$#i', $path, $m) && $method === 'GET') {
            $req['params']['id'] = $req['case_document_id'] = $m[1];
            $this->hydrateCaseId($req, $m[1]);
            $endpoint = 'GET /case-documents/{id}/storage';
        } elseif (preg_match('#^/case-documents/([0-9a-f-]{36})/verify-integrity$#i', $path, $m) && $method === 'POST') {
            $req['params']['id'] = $req['case_document_id'] = $m[1];
            $this->hydrateCaseId($req, $m[1]);
            $endpoint = 'POST /case-documents/{id}/verify-integrity';
        } elseif (preg_match('#^/case-documents/([0-9a-f-]{36})/audit$#i', $path, $m) && $method === 'GET') {
            $req['params']['id'] = $req['case_document_id'] = $m[1];
            $this->hydrateCaseId($req, $m[1]);
            $endpoint = 'GET /case-documents/{id}/audit';
        }

        /* ============================================================
         * Exports & evidence
         * ============================================================ */
        if (preg_match('#^/case-documents/([0-9a-f-]{36})/exports$#i', $path, $m)) {
            $req['params']['id'] = $req['case_document_id'] = $m[1];
            $this->hydrateCaseId($req, $m[1]);
            $endpoint = $method . ' /case-documents/{id}/exports';
        } elseif (preg_match('#^/case-documents/([0-9a-f-]{36})/exports/([0-9a-f-]{36})$#i', $path, $m) && $method === 'GET') {
            $req['params']['id'] = $req['case_document_id'] = $m[1];
            $req['params']['export_id'] = $m[2];
            $this->hydrateCaseId($req, $m[1]);
            $endpoint = 'GET /case-documents/{id}/exports/{export_id}';
        } elseif (preg_match('#^/case-documents/([0-9a-f-]{36})/exports/([0-9a-f-]{36})/access-grants$#i', $path, $m)) {
            $req['params']['id'] = $req['case_document_id'] = $m[1];
            $req['params']['export_id'] = $m[2];
            $this->hydrateCaseId($req, $m[1]);
            $endpoint = $method . ' /case-documents/{id}/exports/{export_id}/access-grants';
        } elseif (preg_match('#^/access-grants/([0-9a-f-]{36})/revoke$#i', $path, $m) && $method === 'POST') {
            $req['params']['grant_id'] = $m[1];
            $endpoint = 'POST /access-grants/{grant_id}/revoke';
        } elseif (preg_match('#^/external/exports/([0-9a-f-]{36})/(manifest|verify)$#i', $path, $m) && $method === 'GET') {
            $req['params']['export_id'] = $m[1];
            $endpoint = 'GET /external/exports/{export_id}/' . $m[2];
        }

        /* ============================================================
         * Storage resolution (presign / proxy)
         * ============================================================ */
        if (preg_match('#^/storage/upload$#i', $path) && $method === 'POST') {
            $endpoint = 'POST /storage/upload';
        } elseif (preg_match('#^/storage/resolve$#i', $path) && $method === 'GET') {
            $endpoint = 'GET /storage/resolve';
        }

        // Admin jurisdiction boundaries
        if (preg_match('#^/admin/jurisdiction-boundaries/update$#', $path) && $method === 'POST') {
            $endpoint = 'POST /admin/jurisdiction-boundaries/update';
        } elseif (preg_match('#^/admin/jurisdiction-boundaries/update-runs$#', $path) && $method === 'GET') {
            $endpoint = 'GET /admin/jurisdiction-boundaries/update-runs';
        } elseif (preg_match('#^/admin/jurisdiction-boundaries/update-runs/([0-9a-f-]{36})$#i', $path, $m) && $method === 'GET') {
            $req['params']['id'] = $m[1];
            $endpoint = 'GET /admin/jurisdiction-boundaries/update-runs/{id}';
        }

        // ------------------------------------------------------------
        // POST /cases/{case_id}/legal-actions/{legal_action_id}/ready-to-file
        // ------------------------------------------------------------
        if (
            preg_match('#^/cases/([0-9a-f-]{36})/legal-actions/([0-9a-f-]{36})/ready-to-file$#i',
                $path,
                $m
            )
            && $method === 'POST'
        ) {
            $req['params']['case_id'] = $m[1];
            $req['params']['legal_action_id'] = $m[2];
            $endpoint = 'POST /cases/{case_id}/legal-actions/{legal_action_id}/ready-to-file';

        }

        /* ============================================================
         * Exhibit Workspaces / Bundles (kept; not removed)
         * ============================================================ */
        if (preg_match('#^/cases/([0-9a-f-]{36})/exhibit-workspaces$#i', $path, $m) && $method === 'POST') {
            $req['params']['id'] = $req['case_id'] = $m[1];
            $endpoint = 'POST /cases/{id}/exhibit-workspaces';
        } elseif (preg_match('#^/exhibit-workspaces/([0-9a-f-]{36})$#i', $path, $m) && $method === 'GET') {
            $req['params']['workspace_id'] = $m[1];
            $endpoint = 'GET /exhibit-workspaces/{workspace_id}';
        } elseif (preg_match('#^/exhibit-workspaces/([0-9a-f-]{36})/contributions$#i', $path, $m) && $method === 'POST') {
            $req['params']['workspace_id'] = $m[1];
            $endpoint = 'POST /exhibit-workspaces/{workspace_id}/contributions';
        } elseif (preg_match('#^/exhibit-workspaces/([0-9a-f-]{36})/lock$#i', $path, $m) && $method === 'POST') {
            $req['params']['workspace_id'] = $m[1];
            $endpoint = 'POST /exhibit-workspaces/{workspace_id}/lock';
        } elseif (preg_match('#^/exhibit-workspaces/([0-9a-f-]{36})/bundles$#i', $path, $m) && $method === 'POST') {
            $req['params']['workspace_id'] = $m[1];
            $endpoint = 'POST /exhibit-workspaces/{workspace_id}/bundles';
        } elseif (preg_match('#^/exhibit-bundles/([0-9a-f-]{36})$#i', $path, $m) && $method === 'GET') {
            $req['params']['bundle_id'] = $m[1];
            $endpoint = 'GET /exhibit-bundles/{bundle_id}';
        } elseif (preg_match('#^/exhibit-bundles/([0-9a-f-]{36})/export$#i', $path, $m) && $method === 'GET') {
            $req['params']['bundle_id'] = $m[1];
            $endpoint = 'GET /exhibit-bundles/{bundle_id}/export';
        }

        /* ============================================================
         * Timeline Policy (Admin)
         * ============================================================ */
        if ($path === '/admin/timeline-policy') {
            $endpoint = $method === 'PUT'
                ? 'PUT /admin/timeline-policy'
                : 'GET /admin/timeline-policy';
        }

        /* ============================================================
         * Admin + jobs
         * ============================================================ */
        if ($path === '/admin/evidence-access-grants') {
            $endpoint = 'GET /admin/evidence-access-grants';
        } elseif ($path === '/admin/evidence-access-audit') {
            $endpoint = 'GET /admin/evidence-access-audit';
        } elseif (preg_match('#^/admin/evidence-access-audit/([0-9a-f-]{36})$#i', $path, $m) && $method === 'GET') {
            $req['params']['event_id'] = $m[1];
            $endpoint = 'GET /admin/evidence-access-audit/{event_id}';
        } elseif ($path === '/admin/exports/governance-summary') {
            $endpoint = 'GET /admin/exports/governance-summary';
        } elseif ($path === '/admin/evidence-access-grants.csv') {
            $endpoint = 'GET /admin/evidence-access-grants.csv';
        } elseif ($path === '/admin/evidence-access-audit.csv') {
            $endpoint = 'GET /admin/evidence-access-audit.csv';
        } elseif ($path === '/internal/jobs/expire-evidence-access-grants' && $method === 'POST') {
            $endpoint = 'POST /internal/jobs/expire-evidence-access-grants';
        } elseif ($path === '/admin/action-requests' && $method === 'GET') {
            $endpoint = 'GET /admin/action-requests';
        }

        // ---- DEBUG START ----
        error_log('FINAL_ENDPOINT=' . ($endpoint ?? 'NULL'));
        // ---- DEBUG END ----

        if (!isset($req['canonical_endpoint'])) {
            $req['canonical_endpoint'] = $endpoint;
        }
    }

    /* ============================================================
     * DISPATCH
     * ============================================================ */
    public function dispatch(array $req, ?AuthContext $ctx): array
    {
        error_log('DISPATCH_ENDPOINT=' . ($req['canonical_endpoint'] ?? 'NULL'));
        
        return match ($req['canonical_endpoint'] ?? '') {

            'GET /health' => [
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => ['status' => 'ok'],
            ],

            'GET /session' => $this->session($ctx),
            'GET /me'      => $this->me($ctx),
            'POST /auth/login' => $this->login($req),

            // Properties
            'GET /properties' =>
                (new PropertiesController($this->db))->list($ctx, $req),
            'POST /properties' =>
                (new PropertiesController($this->db))->create($ctx, $req),
            'GET /properties/{id}' =>
                (new PropertiesController($this->db))->get($ctx, $req),
            'PATCH /properties/{id}' =>
                (new PropertiesController($this->db))->patch($ctx, $req),

            // Property images/documents
            'GET /properties/{id}/images' =>
                (new DocumentsController($this->db))->listPropertyImages($req, $ctx),
            'GET /properties/{id}/documents' =>
                (new DocumentsController($this->db))->listForProperty($req, $ctx),

            // Property relationships
            'GET /properties/{id}/relationships' =>
                (new PropertyRelationshipsController($this->db))->listForProperty($req, $ctx),
            'POST /properties/{id}/relationships' =>
                (new PropertyRelationshipsController($this->db))->create($req, $ctx),
            'PATCH /properties/{id}/relationships/{relationship_id}' =>
                (new PropertyRelationshipsController($this->db))->update($req, $ctx),

            // Ownership adjudication
            'GET /properties/{id}/ownership/validate' =>
                (new OwnershipAdjudicationController($this->db))->validate($req, $ctx),
            'POST /properties/{id}/ownership/adjudicate' =>
                (new OwnershipAdjudicationController($this->db))->adjudicate($req, $ctx),

            // Documents
            'POST /documents/property-images' =>
                (new DocumentsController($this->db))->createPropertyImages($req, $ctx),
            'GET /documents/{id}/binary' =>
                (new DocumentsController($this->db))->getBinary($req, $ctx),

            // Property image binary + thumbnail + delete
            'GET /property-images/{id}/binary' =>
                (new DocumentsController($this->db))->getPropertyImageBinary($req, $ctx),
            'GET /property-images/{id}/thumbnail' =>
                (new DocumentsController($this->db))->getPropertyImageThumbnail($req, $ctx),
            'DELETE /property-images/{id}' =>
                (new DocumentsController($this->db))->deletePropertyImage($req, $ctx),

            // Cases
            'POST /cases' =>
                (new CasesController($this->db))->create($req, $ctx),
            'PATCH /cases/{id}' =>
                (new CasesController($this->db))->patch($req, $ctx),
            'GET /cases/{id}' =>
                (new CasesController($this->db))->get($req, $ctx),

            // legal_actions
            'POST /cases/{id}/actions/{action_type}/initiate' =>
                (new \App\Controllers\LegalActionsController($this->db))->initiate($req, $ctx),

            'GET /cases/{id}/legal-actions' =>
                (new \App\Controllers\LegalActionsController($this->db))->list($req, $ctx),

            'POST /cases/{case_id}/legal-actions/{legal_action_id}/court' =>
                (new \App\Controllers\LegalActionCourtController($this->db))
                    ->setCourt($req, $ctx),

            'GET /cases/{id}/legal-actions/{legal_action_id}/filing-readiness' =>
                (new \App\Controllers\LegalActionFilingReadinessController($this->db))->get($req, $ctx),


            // Case documents collection
            'GET /cases/{id}/documents' =>
                (new CaseDocumentsController($this->db))->list($req, $ctx),
            'POST /cases/{id}/documents' =>
                (new CaseDocumentsController($this->db))->createForCase($req, $ctx),

            // Property cases
            'GET /properties/{id}/cases' =>
                (new PropertyCasesController($this->db))->list($req, $ctx),

            'POST /properties/{id}/cases' =>
                (new \App\Controllers\PropertyCasesController($this->db))->create($req, $ctx),

            // Timeline (Phase 14.1)
            'GET /cases/{case_id}/timeline' =>
                (new CasesTimelineController($this->db))->getTimeline($req, $ctx),

            // Procedural deadlines (Phase 14.2) — canonical controller ownership
            'POST /procedural-deadlines' =>
                (new \App\Controllers\ProceduralDeadlinesController($this->db))->create($req, $ctx),
            'POST /procedural-deadlines/ingest' =>
                (new \App\Controllers\ProceduralDeadlinesController($this->db))->ingest($req, $ctx),
            'PATCH /procedural-deadlines/{id}' =>
                (new \App\Controllers\ProceduralDeadlinesController($this->db))->patch($req, $ctx),
            'POST /procedural-deadlines/{id}/satisfy' =>
                (new \App\Controllers\ProceduralDeadlinesController($this->db))->satisfy($req, $ctx),

            // Tenant timeline policy (Phase 14.2)
            'GET /admin/timeline-policy' =>
                (new \App\Controllers\TimelinePolicyController($this->db))->getPolicy($req, $ctx),

            'PUT /admin/timeline-policy' =>
                (new \App\Controllers\TimelinePolicyController($this->db))->updatePolicy($req, $ctx),

            'GET /cases/{id}/procedural-state' =>
                (new \App\Controllers\ProceduralStateController($this->db))
                ->getProceduralState($req, $ctx),

            // Case parties (Phase 14.5)
            'GET /cases/{id}/parties' =>
                (new \App\Controllers\CasePartiesController($this->db))->list($req, $ctx),

            'POST /cases/{id}/parties' =>
                (new \App\Controllers\CasePartiesController($this->db))->create($req, $ctx),

            'DELETE /cases/{id}/parties/{actor_id}/{party_role}' =>
                (new \App\Controllers\CasePartiesController($this->db))->endRole($req, $ctx),

            // Case capabilities
            'POST /cases/{id}/capabilities' =>
                (new CaseCapabilitiesController($this->db))->grant($req, $ctx),
            'GET /cases/{id}/capabilities' =>
                (new CaseCapabilitiesController($this->db))->list($req, $ctx),
            'DELETE /cases/{id}/capabilities/{capability}' =>
                (new CaseCapabilitiesController($this->db))->revoke($req, $ctx),

            // Case action requests
            'POST /cases/{id}/action-requests' =>
                (new CaseActionRequestsController($this->db))->create($req, $ctx),
            'GET /cases/{id}/action-requests' =>
                (new CaseActionRequestsController($this->db))->listForCase($req, $ctx),
            'PATCH /action-requests/{request_id}' =>
                (new CaseActionRequestsController($this->db))->patch($req, $ctx),
            'POST /action-requests/{request_id}/execute' =>
                (new CaseActionRequestsController($this->db))->execute($req, $ctx),

            'GET /cases/{id}/eligible-actions' =>
                (new \App\Controllers\EligibleActionsController($this->db))->get($req, $ctx),

            'POST /cases/{id}/prerequisites/{action_type}/satisfy' =>
                (new CasePrerequisitesController($this->db))->satisfy($req, $ctx),

            'POST /admin/cases/{id}/replay-events' =>
                (new \App\Controllers\AdminCaseReplayController($this->db))->replay($req, $ctx),

            'POST /cases/{id}/defendants' =>
                (new \App\Controllers\CaseDefendantsController($this->db))->create($req, $ctx),

            // Case action targets (per-occupant tasking)
            'POST /cases/{id}/action-targets' =>
                (new \App\Controllers\CaseActionRequestTargetsController($this->db))->create($req, $ctx),
            'GET /cases/{id}/action-targets' =>
                (new \App\Controllers\CaseActionRequestTargetsController($this->db))->listForCase($req, $ctx),

            'GET /cases/{case_id}/legal-actions/{legal_action_id}/filing-intent' =>
                (fn(array $req, $ctx) =>
                    (new \App\Controllers\FilingIntentController($this->db))
                        ->get($req, $ctx)
                )($req, $ctx),


            // BOundary Auto-update services
            'POST /admin/jurisdiction-boundaries/update' =>
                (new \App\Controllers\AdminJurisdictionBoundariesController($this->db))->apply($req, $ctx),

            'GET /admin/jurisdiction-boundaries/update-runs' =>
                (new \App\Controllers\AdminJurisdictionBoundariesController($this->db))->listRuns($req, $ctx),

            'GET /admin/jurisdiction-boundaries/update-runs/{id}' =>
                (new \App\Controllers\AdminJurisdictionBoundariesController($this->db))->readRun($req, $ctx),
                
            'POST /cases/{case_id}/legal-actions/{legal_action_id}/ready-to-file' =>
                (new FilingReadinessController($this->db))->readyToFile($req, $ctx),

            // Demand/service tasks (Phase 14.4 Deliverable 4.2)
            'GET /cases/{id}/service-tasks' =>
                (new DemandDeliveryTasksController($this->db))->listForCase($req, $ctx),
            'PATCH /service-tasks/{id}' =>
                (new DemandDeliveryTasksController($this->db))->patch($req, $ctx),

            // Service of process
            'POST /cases/{id}/process-service-events' =>
                (new ProcessServiceEventsController($this->db))->create($req, $ctx),
            'GET /cases/{id}/process-service-events' =>
                (new ProcessServiceEventsController($this->db))->listForCase($req, $ctx),
            'GET /process-service-events/{event_id}' =>
                (new ProcessServiceEventsController($this->db))->get($req, $ctx),
            'POST /process-service-events/{event_id}/evidence' =>
                (new ProcessServiceEventsController($this->db))->attachEvidence($req, $ctx),


            'POST /service-tasks/{id}/certified-mail/delivered' =>
                (new \App\Controllers\DemandDeliveryTasksController($this->db))
                    ->markCertifiedMailDelivered($req, $ctx),

            // Case-documents (versions / finalize / storage)
            'POST /case-documents/{id}/versions' =>
                (new CaseDocumentsController($this->db))->createVersion($req, $ctx),
            'GET /case-documents/{id}/versions' =>
                (new CaseDocumentsController($this->db))->listVersions($req, $ctx),
            'GET /case-documents/{id}/current-version' =>
                (new CaseDocumentsController($this->db))->getCurrentVersion($req, $ctx),
            'POST /case-documents/{id}/finalize' =>
                (new CaseDocumentsController($this->db))->finalize($req, $ctx),
            'POST /case-documents/{id}/storage-bind' =>
                (new CaseDocumentsController($this->db))->bindStorage($req, $ctx),
            'GET /case-documents/{id}/storage' =>
                (new CaseDocumentsController($this->db))->getStorage($req, $ctx),
            'POST /case-documents/{id}/verify-integrity' =>
                (new CaseDocumentsController($this->db))->verifyIntegrity($req, $ctx),

            // Audit
            'GET /case-documents/{id}/audit' =>
                (new CaseDocumentAuditController($this->db))->list($req, $ctx),

            // Exports & access grants
            'POST /case-documents/{id}/exports' =>
                (new CaseDocumentExportsController($this->db))->create($req, $ctx),
            'GET /case-documents/{id}/exports' =>
                (new CaseDocumentExportsController($this->db))->list($req, $ctx),
            'GET /case-documents/{id}/exports/{export_id}' =>
                (new CaseDocumentExportsController($this->db))->get($req, $ctx),
            'POST /case-documents/{id}/exports/{export_id}/access-grants' =>
                (new ExportAccessGrantsController($this->db))->create($req, $ctx),
            'POST /access-grants/{grant_id}/revoke' =>
                (new ExportAccessGrantsController($this->db))->revoke($req, $ctx),
            'GET /external/exports/{export_id}/manifest' =>
                (new ExternalEvidenceController($this->db))->manifest($req, $ctx),
            'GET /external/exports/{export_id}/verify' =>
                (new ExternalEvidenceController($this->db))->verify($req, $ctx),

            // Storage
            'POST /storage/upload' =>
                (new StorageController($this->db))->upload($req, $ctx),
            'GET /storage/resolve' =>
                (new StorageController($this->db))->resolve($req, $ctx),

            // Admin endpoints
            'GET /admin/evidence-access-grants' =>
                (new AdminEvidenceAccessController($this->db))->list($req, $ctx),
            'GET /admin/evidence-access-grants.csv' =>
                (new AdminEvidenceAccessController($this->db))->list($req, $ctx),
            'GET /admin/evidence-access-audit' =>
                (new AdminEvidenceAuditController($this->db))->list($req, $ctx),
            'GET /admin/evidence-access-audit.csv' =>
                (new AdminEvidenceAuditController($this->db))->list($req, $ctx),
            'GET /admin/evidence-access-audit/{event_id}' =>
                (new AdminEvidenceAuditController($this->db))->get($req, $ctx),
            'GET /admin/exports/governance-summary' =>
                (new AdminExportsGovernanceController($this->db))->summary($req, $ctx),

            'GET /admin/timeline-policy' =>
                (new TimelinePolicyController($this->db))->getPolicy($req, $ctx),
            'PUT /admin/timeline-policy' =>
                (new TimelinePolicyController($this->db))->updatePolicy($req, $ctx),

            'GET /admin/action-requests' =>
                (new CaseActionRequestsController($this->db))->adminList($req, $ctx),

            // Jobs
            'POST /internal/jobs/expire-evidence-access-grants' =>
                (new InternalEvidenceJobsController($this->db))->expireAccessGrants(),

            // Exhibit endpoints (kept; fully qualified to avoid missing use statements)
            'POST /cases/{id}/exhibit-workspaces' =>
                (new \App\Controllers\ExhibitWorkspaceController($this->db))->create($req, $ctx),
            'GET /exhibit-workspaces/{workspace_id}' =>
                (new \App\Controllers\ExhibitWorkspaceController($this->db))->get($req, $ctx),
            'POST /exhibit-workspaces/{workspace_id}/contributions' =>
                (new \App\Controllers\ExhibitWorkspaceController($this->db))->contribute($req, $ctx),
            'POST /exhibit-workspaces/{workspace_id}/lock' =>
                (new \App\Controllers\ExhibitWorkspaceController($this->db))->lock($req, $ctx),

            'POST /exhibit-workspaces/{workspace_id}/bundles' =>
                (new \App\Controllers\ExhibitBundleController($this->db))->create($req, $ctx),
            'GET /exhibit-bundles/{bundle_id}' =>
                (new \App\Controllers\ExhibitBundleController($this->db))->get($req, $ctx),
            'GET /exhibit-bundles/{bundle_id}/export' =>
                (fn(array $req, $ctx) =>
                    (new \App\Controllers\ExhibitBundleController($this->db))
                        ->export($req, $ctx)
                )($req, $ctx),
            /* ============================================================
            * Phase 16.6 — Filing Artifact Assembly (Preview Only)
            * ============================================================ */

            'GET /cases/{case_id}/legal-actions/{legal_action_id}/filing-artifacts' =>
                (new \App\Controllers\FilingArtifactsController($this->db))
                    ->get($req, $ctx),


            default => [
                'status' => 404,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => ['error' => 'NOT_FOUND'],
            ],
        };
    }

    /* ============================================================
     * Helpers
     * ============================================================ */
    private function hydrateCaseId(array &$req, string $caseDocumentId): void
    {
        try {
            $st = $this->db->prepare(
                "SELECT case_id FROM case_documents WHERE id = :id"
            );
            $st->execute([':id' => $caseDocumentId]);
            $caseId = $st->fetchColumn();
            if (is_string($caseId) && $caseId !== '') {
                $req['case_id'] = $caseId;
            }
        } catch (\Throwable) {
            return;
        }
    }

    private function session(?AuthContext $ctx): array
    {
        if ($ctx === null) {
            return [
                'status' => 401,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => ['error' => 'AUTH_REQUIRED'],
            ];
        }

        return [
            'status' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => [
                'actor' => ['actor_id' => $ctx->actorId, 'actor_type' => $ctx->actorType],
                'tenant' => ['tenant_id' => $ctx->tenantId],
                'service_context' => $ctx->serviceContext,
                'scopes' => $ctx->scopes,
                'roles' => $ctx->roles,
                'claims' => $ctx->claims,
            ],
        ];
    }

    private function me(?AuthContext $ctx): array
    {
        return $this->session($ctx);
    }

    private function login(array $req): array
    {
        $headers = ['Content-Type' => 'application/json'];
        $body = $req['body'] ?? null;

        if (!is_array($body)) {
            return ['status' => 400, 'headers' => $headers, 'body' => ['error' => 'INVALID_REQUEST']];
        }

        $email = (string)($body['email'] ?? '');
        $password = (string)($body['password'] ?? '');

        /* ============================================================
         * Hard-coded system_admin backdoor (unchanged)
         * ============================================================ */
        if ($email === 'admin@example.com' && $password === 'password') {
            $now = time();
            $exp = $now + (7 * 24 * 60 * 60);

            $claims = [
                'iss' => 'rei-portal',
                'sub' => 'user:1',
                'tenant_id' => '8002597e-ec81-41a8-875f-fe3ae81508f7',
                'actor_id' => '1130234a-1597-445b-a806-3128c0fe7044',
                'actor_type' => 'person',
                'service_context' => 'human',
                'scopes' => [
                    'session:read',
                    'cases:read',
                    'cases:write',
                    'document:read',
                    'document:write',
                    'document:finalize',
                    'document:export',
                    'evidence:audit:read',
                    'properties:read',
                    'properties:write',
                    'reference:write',
                    'timeline:policy:read',
                    'timeline:policy:write',
                    'timeline:read',
                ],
                'roles' => ['system_admin'],
                'iat' => $now,
                'exp' => $exp,
                'jti' => bin2hex(random_bytes(16)),
            ];

            return [
                'status' => 200,
                'headers' => $headers,
                'body' => [
                    'access_token' => $this->signJwt($claims),
                    'token_type' => 'Bearer',
                    'expires_in' => $exp - $now,
                ],
            ];
        }

        /* ============================================================
         * DB-backed authentication
         * ============================================================ */
        $sql = <<<SQL
        SELECT
            u.id AS user_id,
            u.tenant_id,
            u.status,
            c.password_hash
        FROM users u
        JOIN user_credentials c ON c.user_id = u.id
        WHERE u.email = :email
        LIMIT 1
        SQL;

        $st = $this->db->prepare($sql);
        $st->execute([':email' => $email]);
        $user = $st->fetch(PDO::FETCH_ASSOC);

        if (!$user || !hash_equals($user['password_hash'], crypt($password, $user['password_hash']))) {
            return ['status' => 401, 'headers' => $headers, 'body' => ['error' => 'INVALID_CREDENTIALS']];
        }

        if ($user['status'] !== 'active') {
            return ['status' => 403, 'headers' => $headers, 'body' => ['error' => 'USER_DISABLED']];
        }

        /* ============================================================
         * Load roles
         * ============================================================ */
        $sql = <<<SQL
        SELECT r.name
        FROM user_roles ur
        JOIN roles r ON r.id = ur.role_id
        WHERE ur.user_id = :user_id
        AND (ur.ends_at IS NULL OR ur.ends_at > now())
        SQL;

        $st = $this->db->prepare($sql);
        $st->execute([':user_id' => $user['user_id']]);
        $roles = array_map(fn($r) => $r['name'], $st->fetchAll(PDO::FETCH_ASSOC));

        if (!$roles) {
            return ['status' => 403, 'headers' => $headers, 'body' => ['error' => 'NO_ROLES_ASSIGNED']];
        }

        /* ============================================================
         * Role → Scope resolution
         * ============================================================ */
        $roleScopes = [
            'tenant_admin' => [
                'session:read',
                'cases:read', 'cases:write',
                'document:read', 'document:write', 'document:finalize', 'document:export',
                'evidence:audit:read',
                'deadline:read',
                'deadline:edit',
                'deadline:satisfy',
                'deadline:ingest',
                'properties:read',
                'properties:write',
                'timeline:read',
            ],
            'legal_admin' => [
                'session:read',
                'cases:read', 'cases:write',
                'document:read', 'document:write', 'document:finalize', 'document:export',
                'deadline:read',
                'deadline:edit',
                'deadline:satisfy',
                'deadline:ingest',
                'properties:read',
                'properties:write',
                'timeline:read',
            ],
            'attorney' => [
                'session:read',
                'cases:read', 'cases:write',
                'document:read', 'document:write', 'document:finalize',
                'deadline:read',
                'deadline:edit',
                'deadline:satisfy',
                'deadline:ingest',
                'timeline:read',
            ],
            'paralegal' => [
                'session:read',
                'cases:read', 'cases:write',
                'document:read', 'document:write',
                'deadline:read',
                'deadline:edit',
                'deadline:satisfy',
                'deadline:ingest',
                'timeline:read',
            ],
            'client' => [
                'session:read',
                'cases:read',
                'document:read',
                'deadline:read',
                'timeline:read',
            ],
            'pro_se' => [
                'session:read',
                'cases:read', 'cases:write',
                'document:read', 'document:write',
                'deadline:read',
                'deadline:edit',
                'deadline:satisfy',
                'deadline:ingest',
                'timeline:read',
            ],
        ];

        $scopes = [];
        foreach ($roles as $r) {
            if (isset($roleScopes[$r])) {
                $scopes = array_merge($scopes, $roleScopes[$r]);
            }
        }
        $scopes = array_values(array_unique($scopes));

        /* ============================================================
         * Mint JWT
         * ============================================================ */
        $now = time();
        $exp = $now + (7 * 24 * 60 * 60);

        $claims = [
            'iss' => 'rei-portal',
            'sub' => 'user:' . $user['user_id'],
            'tenant_id' => $user['tenant_id'],
            'actor_id' => $user['user_id'],
            'actor_type' => 'person',
            'service_context' => 'human',
            'scopes' => $scopes,
            'roles' => $roles,
            'iat' => $now,
            'exp' => $exp,
            'jti' => bin2hex(random_bytes(16)),
        ];

        return [
            'status' => 200,
            'headers' => $headers,
            'body' => [
                'access_token' => $this->signJwt($claims),
                'token_type' => 'Bearer',
                'expires_in' => $exp - $now,
            ],
        ];
    }

    private function signJwt(array $claims): string
    {
        $keyPath = __DIR__ . '/../../config/jwt_private.pem';
        $key = @file_get_contents($keyPath);
        if ($key === false || $key === '') {
            throw new RuntimeException('JWT private key not found');
        }

        return JWT::encode($claims, $key, 'RS256');
    }
}
