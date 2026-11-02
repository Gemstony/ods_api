<?php

namespace App\Http\Requests\Api\V1\Manager;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for a Manager changing a Staff member's status.
 *
 * Branch scope is enforced in [authorize()] — see [UpdateStaffRequest]
 * for rationale (prevents enumeration of cross-branch users via
 * validation error responses).
 */
class ChangeStaffStatusRequest extends FormRequest
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
            throw (new ModelNotFoundException())->setModel(User::class);
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'status' => [
                'required',
                'string',
                Rule::in(['active', 'suspended', 'deactivated']),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'status.in' => 'Status must be active, suspended, or deactivated.',
        ];
    }
}