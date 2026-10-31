<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'address' => $this->address,
            'latitude' => (float) $this->latitude,
            'longitude' => (float) $this->longitude,
            'operating_hours' => $this->operating_hours,
            'status' => $this->status,
            'created_by' => $this->created_by,
            'current_manager' => $this->whenLoaded('managerAssignments', function () {
                $active = $this->managerAssignments
                    ->whereNull('unassigned_at')
                    ->first();
                return $active?->user ? [
                    'id' => $active->user->id,
                    'name' => $active->user->name,
                    'email' => $active->user->email,
                    'assigned_at' => $active->assigned_at?->toIso8601String(),
                ] : null;
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}