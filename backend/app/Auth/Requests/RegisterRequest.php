<?php

namespace App\Auth\Requests;

use App\Auth\Requests\Concerns\CarriesDevice;
use App\Auth\Support\AccountRequirements;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for opening an account.
 *
 * An account is a person, and this request asks what a person is to be
 * recognised: a name, a mailbox, a password. It is not a business, and the four
 * keys at the bottom exist to say so out loud — see `AuthController::register()`.
 *
 * The identity details a deployment demands (`account.require_*`, read through
 * `AccountRequirements`) are deliberately *not* enforced here, and that is a
 * decision with a reason rather than an omission:
 *
 *   - One door, one set of questions. An account opened from a Google assertion
 *     cannot supply a national number it was never asked for, so the platform
 *     needs a completion step for that person regardless. Asking the password
 *     form a different set of questions would leave two kinds of account with
 *     two different paths through the same policy.
 *   - The demand is enforced where the risk is. `POST /organizations` refuses an
 *     incomplete account and names the fields; the session payload says the same
 *     thing, so the screen asks for exactly that. An account that skips it is not
 *     blocked from its own profile — it is blocked from opening a business.
 *   - Asking for less at the door is the standard that holds up: every field a
 *     signup form demands is a field a person can refuse to give a marketplace
 *     they have not yet used.
 *
 * So `phone` and `national_id` are accepted here when offered, and required by
 * the action that needs them.
 */
class RegisterRequest extends FormRequest
{
    use CarriesDevice;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return array_merge([
            // An account is a legal-ish actor on a marketplace: a missing name
            // used to be filled in with "New User" by the controller, which put
            // a made-up identity on counterparty screens, and a missing
            // password reached User::create() as an undefined key — a 500 where
            // a 422 belongs. Required means the caller is told, not guessed for.
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30', 'unique:users,phone'],
            'national_id' => ['nullable', 'string', 'max:100'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],

            // A business is not created here, and saying so is better than
            // ignoring a field that used to work. These four keys are the shape
            // the old registration form sent; each is refused with the route
            // that now takes it, because a client that quietly stops being
            // listened to keeps believing it was heard.
            'organization_name' => ['missing'],
            'organization_type' => ['missing'],
            'commercial_registration_number' => ['missing'],
            'vat_number' => ['missing'],
        ], $this->deviceRules());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'organization_name.missing' => 'المنشأة لا تُنشأ مع الحساب؛ أنشئها بعد إكمال الحساب عبر /organizations ليُطبَّق عليها التحقق من السجل والعدد المسموح.',
            'organization_type.missing' => 'نوع المنشأة يُحدَّد عند إنشائها مستقلةً بعد إكمال الحساب.',
            'commercial_registration_number.missing' => 'رقم السجل التجاري يُقدَّم مع إنشاء المنشأة، لا مع إنشاء الحساب.',
            'vat_number.missing' => 'الرقم الضريبي يُقدَّم مع إنشاء المنشأة، لا مع إنشاء الحساب.',
            'phone.required' => 'رقم الهاتف مطلوب في هذا النشر لإتمام الحساب.',
            'national_id.required' => 'رقم الهوية مطلوب في هذا النشر لإتمام الحساب.',
        ];
    }
}
