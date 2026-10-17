<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Middleware\RoleMiddleware;
use App\Services\AlternativeService;

class AlternativeController
{
    public static function lookup(Request $request, array $routeParams): void
    {
        $medicineId = (int)($routeParams['medicine_id'] ?? $request->getQuery('medicine_id', 0));
        if ($medicineId <= 0) {
            Response::error('Medicine ID is required', 422);
        }

        $result = AlternativeService::getAlternativesForMedicine($medicineId);
        Response::success($result);
    }

    public static function approve(Request $request): void
    {
        RoleMiddleware::authorize(['super_admin', 'doctor']);
        $user = Session::get('user');
        $body = $request->getAllBody();

        $reservationId = (int)($body['reservation_id'] ?? 0);
        $primaryMedId = (int)($body['primary_medicine_id'] ?? 0);
        $altMedId = (int)($body['alternative_medicine_id'] ?? 0);
        $justification = trim((string)($body['clinical_justification'] ?? ''));

        if ($reservationId <= 0 || $primaryMedId <= 0 || $altMedId <= 0 || $justification === '') {
            Response::error('Reservation, Primary Medicine, Alternative Medicine, and Clinical Justification are required.', 422);
        }

        $result = AlternativeService::approveSubstitution([
            'reservation_id'          => $reservationId,
            'primary_medicine_id'     => $primaryMedId,
            'alternative_medicine_id' => $altMedId,
            'clinical_justification'  => $justification,
            'status'                  => $body['status'] ?? 'approved'
        ], (int)$user['id']);

        Response::success($result, 'Clinical substitution authorization recorded successfully');
    }
}
