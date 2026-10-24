<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transforms a User model into the JSON shape Flutter expects.
 *
 * Role is flattened to a string (`$user->role->name` → `"role": "owner"`)
 * because Flutter's `Session` model stores it as `String role`.
 *
 * Role-specific fields (rider details) are conditionally included to keep
 * the Owner/Manager/Distributor responses small.
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'name'               => $this->name,
            'email'              => $this->email,
            'phone'              => $this->phone,
            'role'               => $this->role?->name,
            'status'             => $this->status,
            'branch_id'          => $this->branch_id,
            'distributor_id'     => $this->distributor_id,
            'dispatcher_enabled' => (bool) $this->dispatcher_enabled,
            'email_verified_at'  => $this->email_verified_at?->toIso8601String(),
            'created_at'         => $this->created_at?->toIso8601String(),

            // Permissions — handy for client-side feature gating.
            'permissions' => $this->whenLoaded(
                'role',
                fn () => $this->role->permissions->pluck('name')->values()->all(),
                [],
            ),

            // ─── Rider-specific (only shown for riders) ────────
            'nida'               => $this->when($this->hasRole('rider'), $this->nida),
            'sex'                => $this->when($this->hasRole('rider'), $this->sex),
            'date_of_birth'      => $this->when(
                $this->hasRole('rider'),
                $this->date_of_birth?->toDateString(),
            ),
            'vehicle_type'       => $this->when($this->hasRole('rider'), $this->vehicle_type),
            'vehicle_color'      => $this->when($this->hasRole('rider'), $this->vehicle_color),
            'vehicle_ownership'  => $this->when($this->hasRole('rider'), $this->vehicle_ownership),
            'plate_number'       => $this->when($this->hasRole('rider'), $this->plate_number),
        ];
    }
}