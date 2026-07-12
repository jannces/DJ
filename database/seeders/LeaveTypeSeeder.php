<?php

namespace Database\Seeders;

use App\Models\LeaveType;
use Illuminate\Database\Seeder;

/**
 * Seeds the CSC Form No. 6 (Revised 2020) leave type catalog for LGU Alicia.
 * Rules (detail fields, document requirements, workflow, deadlines) are data,
 * interpreted at runtime by LeavePolicyEngine — admins can edit or add types.
 */
class LeaveTypeSeeder extends Seeder
{
    public function run(): void
    {
        $standardFlow = ['department_head', 'hr', 'mayor'];
        $hrMayorFlow = ['hr', 'mayor'];

        $types = [
            [
                'code' => 'VL', 'name' => 'Vacation Leave', 'category' => 'regular',
                'max_days' => null, 'deductible' => true, 'credit_source' => 'vacation',
                'filing_deadline_days' => 5, 'deadline_is_hard' => false,
                'detail_schema' => [
                    ['name' => 'location', 'label' => 'To be spent', 'type' => 'radio', 'required' => true,
                        'options' => ['within_ph' => 'Within the Philippines', 'abroad' => 'Abroad']],
                    ['name' => 'location_specify', 'label' => 'Specify location', 'type' => 'text', 'required' => true],
                ],
                'required_documents' => [],
                'approval_flow' => $standardFlow, 'annual_reset' => false,
                'description' => 'Deductible from Vacation Leave credits. File at least 5 days before effectivity whenever possible.',
            ],
            [
                'code' => 'FL', 'name' => 'Mandatory / Forced Leave', 'category' => 'regular',
                'max_days' => 5, 'deductible' => true, 'credit_source' => 'vacation',
                'filing_deadline_days' => 0,
                'detail_schema' => [
                    ['name' => 'location', 'label' => 'To be spent', 'type' => 'radio', 'required' => true,
                        'options' => ['within_ph' => 'Within the Philippines', 'abroad' => 'Abroad']],
                ],
                'required_documents' => [],
                'approval_flow' => $standardFlow, 'annual_reset' => true,
                'description' => 'Five (5) days annually per CSC rules; deducted from VL credits; tracked automatically per year.',
            ],
            [
                'code' => 'SL', 'name' => 'Sick Leave', 'category' => 'regular',
                'max_days' => null, 'deductible' => true, 'credit_source' => 'sick',
                'requires_medical_after_days' => 2, // HR rule (>2 days); CSC commutation rule (>5) also satisfied
                'detail_schema' => [
                    ['name' => 'confinement', 'label' => 'Where', 'type' => 'radio', 'required' => true,
                        'options' => ['hospital' => 'In Hospital', 'outpatient' => 'Out Patient']],
                    ['name' => 'illness', 'label' => 'Specify illness', 'type' => 'text', 'required' => true],
                ],
                'required_documents' => [
                    ['type' => 'medical_certificate', 'label' => 'Medical Certificate',
                        'rule' => ['days_gt' => 2]],
                ],
                'approval_flow' => $standardFlow,
                'description' => 'Deductible from Sick Leave credits. Medical certificate required beyond the configured day threshold; late filing after return captures a reason.',
            ],
            [
                'code' => 'ML', 'name' => 'Maternity Leave', 'category' => 'special',
                'max_days' => 105, 'deductible' => false, 'credit_source' => null,
                'detail_schema' => [
                    ['name' => 'expected_delivery', 'label' => 'Expected/Actual date of delivery', 'type' => 'date', 'required' => true],
                    ['name' => 'extension', 'label' => 'Availing additional extension (RA 11210)', 'type' => 'checkbox', 'required' => false],
                ],
                'required_documents' => [
                    ['type' => 'pregnancy_proof', 'label' => 'Proof of pregnancy (ultrasound)', 'rule' => 'always'],
                    ['type' => 'medical_certificate', 'label' => 'Medical Certificate', 'rule' => 'always'],
                ],
                'approval_flow' => $hrMayorFlow,
                'description' => '105 days per RA 11210, CSC compliant; extension where applicable.',
            ],
            [
                'code' => 'PL', 'name' => 'Paternity Leave', 'category' => 'special',
                'max_days' => 7, 'deductible' => false, 'credit_source' => null,
                'detail_schema' => [],
                'required_documents' => [
                    ['type' => 'birth_certificate', 'label' => "Child's Birth Certificate", 'rule' => 'always'],
                    ['type' => 'marriage_certificate', 'label' => 'Marriage Certificate', 'rule' => 'always'],
                    ['type' => 'medical_document', 'label' => 'Medical documents', 'rule' => 'optional'],
                ],
                'approval_flow' => $standardFlow, 'annual_reset' => false,
                'description' => 'Seven (7) days for married male employees, per RA 8187.',
            ],
            [
                'code' => 'SPL', 'name' => 'Special Privilege Leave', 'category' => 'regular',
                'max_days' => 3, 'deductible' => false, 'credit_source' => null,
                'filing_deadline_days' => 7, 'deadline_is_hard' => false,
                'detail_schema' => [
                    ['name' => 'location', 'label' => 'To be spent', 'type' => 'radio', 'required' => true,
                        'options' => ['within_ph' => 'Within the Philippines', 'abroad' => 'Abroad']],
                    ['name' => 'travel_details', 'label' => 'Purpose / travel details', 'type' => 'textarea', 'required' => true],
                ],
                'required_documents' => [],
                'approval_flow' => $standardFlow, 'annual_reset' => true,
                'description' => 'Three (3) days annually; recommend filing at least 1 week ahead.',
            ],
            [
                'code' => 'SOLO', 'name' => 'Solo Parent Leave', 'category' => 'special',
                'max_days' => 7, 'deductible' => false, 'credit_source' => null,
                'detail_schema' => [],
                'required_documents' => [
                    ['type' => 'solo_parent_id', 'label' => 'Solo Parent ID (DSWD)', 'rule' => 'always'],
                ],
                'approval_flow' => $standardFlow, 'annual_reset' => true,
                'description' => 'Seven (7) days annually per RA 8972; requires a valid Solo Parent ID.',
            ],
            [
                'code' => 'STL', 'name' => 'Study Leave', 'category' => 'special',
                'max_days' => 180, 'deductible' => false, 'credit_source' => null,
                'detail_schema' => [
                    ['name' => 'purpose', 'label' => 'Purpose', 'type' => 'radio', 'required' => true,
                        'options' => [
                            'masters' => "Completion of Master's Degree",
                            'bar' => 'BAR Review', 'board' => 'Board Examination Review',
                            'other' => 'Other',
                        ]],
                    ['name' => 'purpose_other', 'label' => 'If other, specify', 'type' => 'text', 'required' => false],
                ],
                'required_documents' => [
                    ['type' => 'study_contract', 'label' => 'Study leave contract', 'rule' => 'always'],
                    ['type' => 'supporting_document', 'label' => 'Supporting documents (enrolment/registration)', 'rule' => 'always'],
                ],
                'approval_flow' => $hrMayorFlow,
                'description' => 'Up to six (6) months for study purposes with contract and supporting documents.',
            ],
            [
                'code' => 'VAWC', 'name' => '10-Day VAWC Leave', 'category' => 'special',
                'max_days' => 10, 'deductible' => false, 'credit_source' => null,
                'detail_schema' => [],
                'required_documents' => [
                    ['type' => 'vawc_document', 'label' => 'Barangay Protection Order / Court Order / Medical Certificate / Police Report',
                        'rule' => 'always'],
                ],
                'approval_flow' => $hrMayorFlow, 'annual_reset' => true,
                'description' => 'Ten (10) days per RA 9262 with any qualifying supporting document.',
            ],
            [
                'code' => 'RL', 'name' => 'Rehabilitation Privilege Leave', 'category' => 'special',
                'max_days' => 180, 'deductible' => false, 'credit_source' => null,
                'detail_schema' => [
                    ['name' => 'accident_details', 'label' => 'Details of work-related accident', 'type' => 'textarea', 'required' => true],
                ],
                'required_documents' => [
                    ['type' => 'accident_report', 'label' => 'Accident report', 'rule' => 'always'],
                    ['type' => 'medical_certificate', 'label' => 'Medical Certificate', 'rule' => 'always'],
                    ['type' => 'physician_recommendation', 'label' => 'Government physician recommendation', 'rule' => 'always'],
                ],
                'approval_flow' => $hrMayorFlow,
                'description' => 'Up to six (6) months for work-related injury rehabilitation.',
            ],
            [
                'code' => 'SLBW', 'name' => 'Special Leave Benefits for Women', 'category' => 'special',
                'max_days' => 60, 'deductible' => false, 'credit_source' => null,
                'detail_schema' => [
                    ['name' => 'illness', 'label' => 'Gynecological illness', 'type' => 'text', 'required' => true],
                    ['name' => 'surgery_details', 'label' => 'Surgery details', 'type' => 'textarea', 'required' => true],
                ],
                'required_documents' => [
                    ['type' => 'medical_certificate', 'label' => 'Medical Certificate', 'rule' => 'always'],
                    ['type' => 'clinical_summary', 'label' => 'Clinical summary', 'rule' => 'always'],
                    ['type' => 'surgery_document', 'label' => 'Surgery record/details', 'rule' => 'always'],
                ],
                'approval_flow' => $hrMayorFlow,
                'description' => 'Up to two (2) months per RA 9710 following surgery for gynecological disorders.',
            ],
            [
                'code' => 'SEL', 'name' => 'Special Emergency (Calamity) Leave', 'category' => 'special',
                'max_days' => 5, 'deductible' => false, 'credit_source' => null,
                'detail_schema' => [
                    ['name' => 'calamity', 'label' => 'Declared calamity', 'type' => 'text', 'required' => true],
                    ['name' => 'calamity_area', 'label' => 'Affected area (must match residence)', 'type' => 'text', 'required' => true],
                ],
                'required_documents' => [
                    ['type' => 'government_proof', 'label' => 'Government declaration / supporting documents', 'rule' => 'always'],
                ],
                'approval_flow' => $hrMayorFlow, 'annual_reset' => true,
                'description' => 'Up to five (5) days; HR validates residence against the declared calamity area.',
            ],
            [
                'code' => 'MON', 'name' => 'Monetization of Leave Credits', 'category' => 'monetization',
                'max_days' => null, 'deductible' => true, 'credit_source' => 'vacation',
                'detail_schema' => [
                    ['name' => 'reason', 'label' => 'Reason for monetization', 'type' => 'textarea', 'required' => true],
                    ['name' => 'days_to_monetize', 'label' => 'Number of days to monetize', 'type' => 'number', 'required' => true],
                ],
                'required_documents' => [
                    ['type' => 'letter_request', 'label' => 'Letter request', 'rule' => 'always'],
                ],
                'approval_flow' => $hrMayorFlow,
                'description' => 'Converts leave credits to cash; requires HR approval then Mayor approval.',
            ],
            [
                'code' => 'TL', 'name' => 'Terminal Leave', 'category' => 'terminal',
                'max_days' => null, 'deductible' => true, 'credit_source' => 'vacation',
                'detail_schema' => [
                    ['name' => 'separation_type', 'label' => 'Separation', 'type' => 'radio', 'required' => true,
                        'options' => ['retirement' => 'Retirement', 'resignation' => 'Resignation']],
                ],
                'required_documents' => [
                    ['type' => 'clearance', 'label' => 'Clearance', 'rule' => 'always'],
                    ['type' => 'separation_document', 'label' => 'Resignation / retirement documents', 'rule' => 'always'],
                ],
                'approval_flow' => $hrMayorFlow,
                'description' => 'Money value of accumulated credits upon retirement or resignation.',
            ],
            [
                'code' => 'AL', 'name' => 'Adoption Leave', 'category' => 'special',
                'max_days' => 60, 'deductible' => false, 'credit_source' => null,
                'detail_schema' => [],
                'required_documents' => [
                    ['type' => 'papa_document', 'label' => 'Pre-Adoption Placement Authority (PAPA)', 'rule' => 'always'],
                    ['type' => 'dswd_document', 'label' => 'DSWD documents', 'rule' => 'always'],
                ],
                'approval_flow' => $hrMayorFlow,
                'description' => 'Adoption leave per RA 8552 with DSWD documentation.',
            ],
        ];

        foreach ($types as $type) {
            LeaveType::updateOrCreate(['code' => $type['code']], $type + ['active' => true, 'is_custom' => false]);
        }
    }
}
