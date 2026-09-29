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

            // if ($role === 'coapplicant') {
            //     $person     = CoapplicantKyc::where('borrower_id', $borrower->borrower_id)->latest()->first();
            //     $occupation = CoapplicantOccupation::where('borrower_id', $borrower->borrower_id)->latest()->first();
            // } else {
            //     $person     = Guarantor::where('borrower_id', $borrower->borrower_id)->latest()->first();
            //     $occupation = GuarantorOccupation::where('borrower_id', $borrower->borrower_id)->latest()->first();
            // }

            if ($role === 'coapplicant') {
                $person     = CoapplicantKyc::where('borrower_id', $borrower->id)->latest()->first();
                $occupation = CoapplicantOccupation::where('borrower_id', $borrower->id)->latest()->first();
            } else {
                $person     = Guarantor::where('borrower_id', $borrower->id)->latest()->first();
                $occupation = GuarantorOccupation::where('borrower_id', $borrower->id)->latest()->first();
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

    protected function buildPayload(BorrowerKyc $borrower, $person, $occupation, string $role): array
    {
        [$firstName, $lastName] = $this->splitName($person->name);

        $bid = $borrower->id;

        $salutationId     = $this->ref->getId($bid, 'salutation', 'Mr.');
        $genderId         = $this->ref->getId($bid, 'genderIdOptions', $person->gender ?? null, 'Male');
        $relationShipId   = $this->ref->getId($bid, 'relationShipIdOptions', null, 'Spouse');
        $maritalStatusId  = $this->ref->getId($bid, 'maritalStatusIdOptions', null, 'Unmarried');
        $professionId     = $this->ref->getId($bid, 'professionIdOptions', null, 'OTHER');
        $qualificationId  = $this->ref->getId($bid, 'qualificationIdOptions', null, 'GRADUATE');
        $legalFormId      = $this->ref->getId($bid, 'clientLegalFormOptions', null, 'INDIVIDUAL');
        $addressTypeId    = $this->ref->getId($bid, 'addressTypeOptions', null, 'Owned');
        $addressSubTypeId = $this->ref->getId($bid, 'addressSubTypeOptions', null, 'Current');

        $addressLine = $occupation->current_address ?? '';
        $postalCode  = $this->extractPostalCode($addressLine);

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
            'fathersName'          => $person->fs_name ?? $person->father_name ?? null,
            'maritalStatusId'      => (int) $maritalStatusId,
            'professionId'         => (int) $professionId,
            'qualificationId'      => (int) $qualificationId,
            'legalFormId'          => (int) $legalFormId,

            'isDependent'          => false,
            'isFamily'             => false,
            'livingWithApplicant'  => false,
            'isConsolidate'        => false,
            'isIncludedInReport'   => true,
            'personWithDisability' => false,
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

            'address' => [[
                'addressTypeId'    => (int) $addressTypeId,
                'addressSubTypeId' => (int) $addressSubTypeId,
                'addressLine1'     => $addressLine ?: 'N/A',
                'landmark'         => 'N/A',
                'city'             => 'N/A',
                'district'         => 'N/A',
                'state'            => 'N/A',
                'country'          => 'India',
                'postalCode'       => $postalCode ?? '000000',
                'isActive'         => true,
                'locale'           => 'en',
                'dateFormat'       => 'dd MMMM yyyy',
            ]],

            'kycData' => $this->buildKycData($person, $role),

            'assets'      => [],
            'incomes'     => [],
            'liabilities' => [],
            'kyc'         => [],
        ]);
    }

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

    protected function splitName(?string $name): array
    {
        $name = trim($name ?? '');
        if (empty($name)) return ['', ''];
        $parts = explode(' ', $name, 2);
        return [$parts[0] ?? '', $parts[1] ?? ''];
    }

    protected function extractPostalCode(?string $address): ?string
    {
        if (preg_match('/(\d{6})/', $address ?? '', $m)) {
            return $m[1];
        }
        return null;
    }
}