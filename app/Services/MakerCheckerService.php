<?php

namespace App\Services;

use App\Models\MakerCheckerAuthorization;
use App\Models\ModuleFunctionality;

class MakerCheckerService
{
    public function mfuncId(string $routeUsed): ?int
    {
        return ModuleFunctionality::where('route_used', $routeUsed)
            ->where('row_status', 'A')
            ->value('mfunc_id');
    }

    /** @return array{authorized:bool,message:string,authorization:?MakerCheckerAuthorization,mfunc_id:?int} */
    public function authorizeMaker(string $routeUsed, int $makerId): array
    {
        $mfuncId = $this->mfuncId($routeUsed);
        if (!$mfuncId) {
            return ['authorized' => false, 'message' => 'Route not found in module functionalities', 'authorization' => null, 'mfunc_id' => null];
        }

        $authorization = MakerCheckerAuthorization::where('mfunc_id', $mfuncId)
            ->where('maker_id', $makerId)
            ->where('row_status', 'A')
            ->first();

        if (!$authorization) {
            return ['authorized' => false, 'message' => 'You are not authorized to perform this action', 'authorization' => null, 'mfunc_id' => $mfuncId];
        }

        return ['authorized' => true, 'message' => 'Authorized', 'authorization' => $authorization, 'mfunc_id' => $mfuncId];
    }

    /** Authorization row where $reviewerId is R1 (or R2) for the given maker. */
    public function authorizationForReviewer(string $routeUsed, int $makerId, int $reviewerId, string $level): ?MakerCheckerAuthorization
    {
        $mfuncId = $this->mfuncId($routeUsed);
        if (!$mfuncId) {
            return null;
        }

        return MakerCheckerAuthorization::where('mfunc_id', $mfuncId)
            ->where('maker_id', $makerId)
            ->where($level === 'r1' ? 'r1_id' : 'r2_id', $reviewerId)
            ->where('row_status', 'A')
            ->first();
    }
}