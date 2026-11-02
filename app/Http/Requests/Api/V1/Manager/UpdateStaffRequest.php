<?php

namespace App\Http\Requests\Api\V1\Manager;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for a Manager updating a Staff member's basic info.
 *
 * Branch scope is enforced in [authorize()] — **before** field-level
 * validation runs. This prevents branch-enumeration attacks: an
 * attacker sending valid-shaped payloads for another branch's user
 * gets the same 404 they'd get for a nonexistent ID, without leaking
 * whether that user is a Rider (requires `nida`) or Receiver (requires
 * `dispatcher_enabled`).
 *
 * Only `name`, `phone`, and role-specific operational fields are
 * editable. Email, role, and branch are immutable here.
 */
class UpdateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User|null $target */
        $target = $this->route('user');
        if (! $target instanceof User) {
            return false;
        }

        $managerBranchId = $this->user()?->branch_id;

        $isOwnBranchStaff =
            $managerBranchId !== null
            && $target->branch_id === $managerBranchId
            && (
                $target->hasRole(Role::RECEIVER)
                || $target->hasRole(Role::RIDER)
            );

        if (! $isOwnBranchStaff) {
            // Throwing ModelNotFoundException gives the standard 404
            // envelope handled in bootstrap/app.php — identical to the
            // response a manager would get for a nonexistent ID.
            throw (new ModelNotFoundException())->setModel(User::class);
        }

        return true;
    }

    public function rules(): array
    {
        /** @var User $target */
        $target = $this->route('user');
        $isRider = $target->hasRole(Role::RIDER);
        $isReceiver = $target->hasRole(Role::RECEIVER);

        $rules = [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'phone' => [
                'required',
                'string',
                'regex:/^(?:\+?255|0)(?:6[1-9]|7[1-9])\d{7}$/',
                Rule::unique('users', 'phone')->ignore($target->id),
            ],
        ];

        if ($isReceiver) {
            $rules['dispatcher_enabled'] = ['nullable', 'boolean'];
        }

        if ($isRider) {
            $rules['nida'] = ['required', 'string', 'size:20', 'regex:/^\d+$/'];
            $rules['sex'] = ['required', Rule::in(['male', 'female', 'other'])];
            $rules['date_of_birth'] = ['required', 'date', 'before:today'];
            $rules['vehicle_type'] = ['required', 'string', 'max:50'];
            $rules['vehicle_color'] = ['required', 'string', 'max:50'];
            $rules['vehicle_ownership'] = [
                'required',
                Rule::in(['owned', 'rented', 'company']),
            ];
            $rules['plate_number'] = ['required', 'string', 'max:20'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return (new StoreStaffRequest())->messages();
    }
}