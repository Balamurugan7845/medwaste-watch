<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\ReportService;

class ReportController
{
    public static function index(Request $request): void
    {
        $type = (string)$request->getQuery('type', 'current_stock');

        switch ($type) {
            case 'current_stock':
                $data = ReportService::getCurrentStockReport();
                break;
            case 'batch_wise':
                $data = ReportService::getBatchWiseReport();
                break;
            case 'wastage':
                $data = ReportService::getWastageReport();
                break;
            case 'movements':
                $data = ReportService::getMovementsReport();
                break;
            default:
                Response::error('Invalid report type requested', 400);
        }

        Response::success([
            'type'  => $type,
            'items' => $data,
            'count' => count($data)
        ]);
    }

    public static function export(Request $request): void
    {
        $type = (string)$request->getQuery('type', 'current_stock');

        try {
            $result = ReportService::generateCsv($type);
            Response::csvDownload($result['filename'], $result['csv']);
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 400);
        }
    }
}
