<?php

namespace App\Services\Graviton;

use App\Models\BorrowerKyc;
use App\Models\CoapplicantKyc;
use App\Models\CoapplicantOccupation;
use App\Models\Guarantor;
use App\Models\GuarantorOccupation;
use App\Models\GravitonApplicationLog;
use Illuminate\Support\Facades\Log;

class GravitonFamilyMemberPushService
{
    public function __construct(
        private GravitonAuthService $auth,
        private GravitonReferenceHelperService $ref
    ) {}

    /**
     * Push Co-Applicant or Guarantor for a borrower
     */
    public function push(BorrowerKyc $borrower, string $role): array
    {
        if (!in_array($role, ['coapplicant', 'guarantor'])) {
            throw new \Exception("Invalid role: {$role}");
        }

        $log = GravitonApplicationLog::create([
            'borrower_id'  => $borrower->id,
            'step'         => $role . '_push',
            'entity_type'  => $role,
            'endpoint'     => '/clients/{clientId}/familymembers',
            'status'       => 'pending',
            'requested_at' => now(),
        ]);

        try {
            if (empty($borrower->graviton_client_id)) {
                throw new \Exception("Borrower {$borrower->id} has no graviton_client_id");
            }

            // ✅ Person + Occupation fetch
            if ($role === 'coapplicant') {
                $person     = CoapplicantKyc::where('borrower_id', $borrower->borrower_id)->latest()->first();
                $occupation = CoapplicantOccupation::where('borrower_id', $borrower->borrower_id)->latest()->first();
            } else {
                $person     = Guarantor::where('borrower_id', $borrower->borrower_id)->latest()->first();
                $occupation = GuarantorOccupation::where('borrower_id', $borrower->borrower_id)->latest()->first();
            }

            if (!$person) {
                $log->update([
                    'status'        => 'skipped',
                    'error_message' => "No {$role} found for borrower",
                    'completed_at'  => now(),
                ]);
                return ['status' => 'skipped', 'reason' => "no_{$role}"];
            }

            $payload = $this->buildPayload($borrower, $person, $occupation, $role);
            $log->update(['request_payload' => $payload]);

            $clientId = $borrower->graviton_client_id;
            $response = $this->auth->client()
                ->post("/clients/{$clientId}/familymembers", $payload);

            $data = $response->json();

            $log->update([
                'response_payload' => $data,
                'http_status'      => $response->status(),
            ]);

            if (!$response->successful()) {
                $log->update([
                    'status'            => 'failed',
                    'error_message'     => 'HTTP ' . $response->status(),
                    'validation_errors' => $data['errors'] ?? null,
                    'completed_at'      => now(),
                ]);
                throw new \Exception("{$role} push failed: HTTP " . $response->status());
            }

            $log->update([
                'status'             => 'success',
                'graviton_client_id' => $data['clientId']   ?? null,
                'resource_id'        => $data['resourceId'] ?? null,
                'completed_at'       => now(),
            ]);

            Log::info("{$role} pushed", [
                'borrower_id' => $borrower->id,
                'resource_id' => $data['resourceId'] ?? null,
            ]);

            return $data;

        } catch (\Throwable $e) {
            $log->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at'  => now(),
            ]);
            Log::error("{$role} push FAILED", [
                'borrower_id' => $borrower->id,
                'error'       => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * ✅ Build payload — with all mapping fixes
     */
    protected function buildPayload(BorrowerKyc $borrower, $person, $occupation, string $role): array
    {
        // ✅ Father name for fallback
        $fatherName = $person->fs_name ?? $person->father_name ?? null;

        // ✅ Split name — last name empty hole "So {fatherName}"
        [$firstName, $lastName] = $this->splitName($person->name, $fatherName);

        $bid = $borrower->id;

        // ═══════════════════════════════════════════════════════
        // Salutation — gender + marital status onujayi
        // ═══════════════════════════════════════════════════════
        $salutationKey = match (true) {
            strtolower(trim($person->gender ?? '')) === 'male' => 'Mr.',
            strtolower(trim($person->gender ?? '')) === 'female' && strtolower(trim($person->marital_status ?? '')) === 'married' => 'Mrs.',
            strtolower(trim($person->gender ?? '')) === 'female' => 'Ms.',
            default => 'Mr.',
        };

        $salutationId = $this->ref->getId($bid, 'salutation', $salutationKey);
        if (!$salutationId) {
            $salutationId = $this->ref->getId($bid, 'salutation', 'Mr.');
        }

        // ═══════════════════════════════════════════════════════
        // ✅ Gender — relationship theke override
        // ═══════════════════════════════════════════════════════
        $relationship = strtoupper($person->relationship ?? $person->relation ?? '');
        $genderFromSource = $person->gender ?? null;

        $genderId = $this->ref->getId($bid, 'genderIdOptions', $genderFromSource, 'Male');

        // ✅ Relationship "FATHER"/"HUSBAND"/etc. hole gender override
        if (in_array($relationship, ['FATHER', 'HUSBAND', 'SON', 'BROTHER'])) {
            $genderId = $this->ref->getId($bid, 'genderIdOptions', 'Male', 'Male');
        } elseif (in_array($relationship, ['MOTHER', 'WIFE', 'DAUGHTER', 'SISTER'])) {
            $genderId = $this->ref->getId($bid, 'genderIdOptions', 'Female', 'Female');
        }

        // ═══════════════════════════════════════════════════════
        // Other reference IDs
        // ═══════════════════════════════════════════════════════
        $relationShipId   = $this->ref->getId($bid, 'relationShipIdOptions', null, 'Spouse');
        $maritalStatusId  = $this->ref->getId($bid, 'maritalStatusIdOptions', $person->marital_status ?? null, 'Unmarried');
        $professionId     = $this->ref->getId($bid, 'professionIdOptions', null, 'OTHER');
        $qualificationId  = $this->ref->getId($bid, 'qualificationIdOptions', null, 'GRADUATE');
        $legalFormId      = $this->ref->getId($bid, 'clientLegalFormOptions', null, 'INDIVIDUAL');
        $addressTypeId    = $this->ref->getId($bid, 'addressTypeOptions', null, 'Owned');
        $addressSubTypeId = $this->ref->getId($bid, 'addressSubTypeOptions', null, 'Current');

        // ═══════════════════════════════════════════════════════
        // ✅ Address parsing — city, district, state, pincode
        // ═══════════════════════════════════════════════════════
        $addressLine = trim((string) ($occupation->current_address ?? ''));

        // ✅ Duplicate remove
        $parts = array_filter(array_map('trim', explode(',', $addressLine)));
        $parts = array_values(array_unique($parts));

        // ✅ Trailing N/A remove
        $parts = array_filter($parts, fn($p) => strtoupper($p) !== 'N/A');

        // ✅ Rebuild address
        $addressLine = implode(', ', $parts);

        // ✅ India shudhu country field e rakho
        $addressLine = str_ireplace(', India', '', $addressLine);

        // ✅ Pincode — address theke parse
        $postalCode = '000000';
        if (preg_match('/(\d{6})/', $addressLine, $m)) {
            $postalCode = $m[1];
        }

        // ✅ Address line split (max 100 chars each)
        $addressLine1 = mb_substr($addressLine, 0, 100);
        $addressLine2 = mb_strlen($addressLine) > 100 ? mb_substr($addressLine, 100, 100) : null;
        $addressLine3 = mb_strlen($addressLine) > 200 ? mb_substr($addressLine, 200, 100) : null;

        // ✅ City — address theke parse
        $city = 'N/A';
        if (preg_match('/,\s*([^,]+),\s*[^,]+,\s*\d{6}/', $addressLine, $m)) {
            $city = trim($m[1]);
        }

        // ✅ District — address theke parse
        $district = 'N/A';
        if (preg_match('/,\s*([^,]+),\s*[^,]+,\s*[^,]+,\s*\d{6}/', $addressLine, $m)) {
            $district = trim($m[1]);
        }

        // ✅ State — address theke parse
        $state = 'N/A';
        if (preg_match('/,\s*([^,]+),\s*\d{6}/', $addressLine, $m)) {
            $state = trim($m[1]);
        }

        // ═══════════════════════════════════════════════════════
        // ✅ Age calculation
        // ═══════════════════════════════════════════════════════
        $age = null;
        if (!empty($person->dob_date)) {
            $dob = new \DateTime($person->dob_date);
            $age = (new \DateTime())->diff($dob)->y;
        }

        // ═══════════════════════════════════════════════════════
        // ✅ Role-specific fields
        // ═══════════════════════════════════════════════════════
        $roleFields = $role === 'coapplicant'
            ? [
                'familyMemberTypeEnum' => 1,
                'isCoApplicant'        => true,
                'isGuarantor'          => false,
            ]
            : [
                'familyMemberTypeEnum' => 2,
                'isCoApplicant'        => false,
                'isGuarantor'          => true,
            ];

        return array_merge($roleFields, [
            'loanId' => (int) ($borrower->graviton_loan_id ?? 0),

            'firstName'            => $firstName,
            'lastName'             => $lastName,
            'relationShipId'       => (int) $relationShipId,
            'mobileNo'             => $person->mobile,
            'salutationId'         => (int) $salutationId,
            'genderId'             => (int) $genderId,
            'dateOfBirth'          => $person->dob_date
                ? date('d F Y', strtotime($person->dob_date))
                : null,
            'age'                  => $age,
            'fathersName'          => $fatherName,
            'maritalStatusId'      => (int) $maritalStatusId,
            'professionId'         => (int) $professionId,
            'qualificationId'      => (int) $qualificationId,
            'legalFormId'          => (int) $legalFormId,

            // ═══════════════════════════════════════
            // ✅ NEW — Category A fields
            // ═══════════════════════════════════════
            'middleName'           => $person->middle_name ?? null,
            // 'motherName'           => $person->mother_name ?? null,
            'emailAddress'         => $person->email ?? null,
            'placeOfBirth'         => $person->place_of_birth ?? null,

            'isDependent'          => (bool) ($person->is_dependent ?? false),
            'isFamily'             => (bool) ($person->is_family ?? false),
            'livingWithApplicant'  => (bool) ($person->living_with_applicant ?? false),
            'isConsolidate'        => false,
            'isIncludedInReport'   => true,
            'personWithDisability' => (bool) ($person->person_with_disability ?? false),
            'isMobileVerified'     => (bool) ($person->is_verified_mobile ?? false),

            'clientNameJson' => [
                'manuallyEnteredName' => $person->name ?? '',
                'mobileVerifiedName'  => '',
                'aadharCardName'      => $person->name ?? '',
                'panCardName'         => strtoupper($person->name ?? ''),
            ],

            'mobileKycJson' => null,
            'faceMatchJson' => null,
            'locale'        => 'en',
            'dateFormat'    => 'dd MMMM yyyy',

            // ═══════════════════════════════════════
            // Address
            // ═══════════════════════════════════════
            'address' => [[
                'addressTypeId'          => (int) $addressTypeId,
                'addressSubTypeId'       => (int) $addressSubTypeId,
                'addressLine1'           => $addressLine1 ?: 'N/A',
                'addressLine2'           => $addressLine2 ?: null,
                'addressLine3'           => $addressLine3 ?: null,
                'landmark'               => $occupation->landmark ?? 'N/A',
                'city'                   => $city,
                'district'               => $district,
                'state'                  => $state,
                'country'                => 'India',
                'postalCode'             => $postalCode,
                'longitude'              => $occupation->longitude ?? null,
                'latitude'               => $occupation->latitude ?? null,
                'isCommunicationAddress' => (bool) ($occupation->is_communication ?? true),
                'isActive'               => true,
                'locale'                 => 'en',
                'dateFormat'             => 'dd MMMM yyyy',
            ]],

            'kycData' => $this->buildKycData($person, $role),

            'assets'      => [],
            'incomes'     => [],
            'liabilities' => [],
            'kyc'         => [],
        ]);
    }

    /**
     * ✅ Build KYC data
     */
    protected function buildKycData($person, string $role): array
    {
        $kyc    = [];
        $entity = $role;

        if (!empty($person->pan)) {
            $kyc[] = [
                'entity'             => $entity,
                'verificationTypeId' => 1,
                'documentTypeId'     => 2,
                'documentKey'        => $person->pan,
                'kycTypeId'          => 0,
                'validationStatusId' => $person->is_verified_pan ? 1 : 0,
                'isVerified'         => (bool) $person->is_verified_pan,
                'isFrontSide'        => true,
                'isBackSide'         => false,
                'isOCR'              => false,
                'locale'             => 'en',
                'pdfUrl'             => (object) [],
            ];
        }

        if (!empty($person->aadhar)) {
            $kyc[] = [
                'entity'             => $entity,
                'verificationTypeId' => 1,
                'documentTypeId'     => 1,
                'documentKey'        => $person->aadhar,
                'kycTypeId'          => 0,
                'validationStatusId' => $person->is_verified_aadhar ? 1 : 0,
                'isVerified'         => (bool) $person->is_verified_aadhar,
                'isFrontSide'        => true,
                'isBackSide'         => true,
                'isOCR'              => false,
                'locale'             => 'en',
                'pdfUrl'             => (object) [],
            ];
        }

        return $kyc;
    }

    /**
     * ✅ Split name — last name empty hole "So {fatherName}"
     */
    protected function splitName(?string $name, ?string $fatherName = null): array
    {
        $name = trim($name ?? '');
        if (empty($name)) {
            return ['N/A', 'N/A'];
        }

        $parts     = explode(' ', $name, 2);
        $firstName = trim($parts[0] ?? '');
        $lastName  = trim($parts[1] ?? '');

        // ✅ Last name empty hole "So {fatherName}"
        if (empty($lastName)) {
            $lastName = !empty($fatherName)
                ? 'So ' . trim($fatherName)
                : $firstName;
        }

        // ✅ First name empty hole last name
        if (empty($firstName)) {
            $firstName = $lastName;
        }

        return [$firstName, $lastName];
    }

    /**
     * ✅ Extract postal code
     */
    protected function extractPostalCode(?string $address): ?string
    {
        if (preg_match('/(\d{6})/', $address ?? '', $m)) {
            return $m[1];
        }
        return null;
    }
}