<?php

namespace Database\Seeders;

use App\Models\AppointmentRequest;
use App\Models\DoctorAvailability;
use App\Models\Hospital;
use App\Models\KnowledgeBase;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoHospitalSeeder extends Seeder
{
    public function run(): void
    {
        $hadCurrentHospital = app()->bound('currentHospital');
        $previousHospital = $hadCurrentHospital ? app('currentHospital') : null;

        try {
            DB::transaction(function (): void {
                $hospital = Hospital::withoutGlobalScopes()->firstOrNew(['slug' => 'demo']);
                $hospital->forceFill([
                    'name' => 'Demo Hospital',
                    'chatbot_name' => 'MediDesk',
                    'logo_url' => '/images/demo-hospital-logo.svg',
                    'primary_color' => '#0a2f44',
                    'secondary_color' => '#06b6d4',
                    'site_header_text' => 'Demo Hospital',
                    'site_footer_text' => '© 2026 Demo Hospital. All rights reserved.',
                    'address' => 'Nairobi CBD, Kenya',
                    'city' => 'Nairobi',
                    'county' => 'Nairobi',
                    'phone' => '0700000000',
                    'emergency_phone' => '0700000000',
                    'email' => 'hello@demohospital.test',
                    'website' => 'https://demohospital.test',
                    'hours_outpatient' => '8:00 AM - 6:00 PM, Mon-Fri; 9:00 AM - 1:00 PM, Sat',
                    'hours_emergency' => '24/7',
                    'business_hours' => [
                        'monday' => ['open' => '08:00', 'close' => '18:00', 'closed' => false],
                        'tuesday' => ['open' => '08:00', 'close' => '18:00', 'closed' => false],
                        'wednesday' => ['open' => '08:00', 'close' => '18:00', 'closed' => false],
                        'thursday' => ['open' => '08:00', 'close' => '18:00', 'closed' => false],
                        'friday' => ['open' => '08:00', 'close' => '18:00', 'closed' => false],
                        'saturday' => ['open' => '09:00', 'close' => '13:00', 'closed' => false],
                        'sunday' => ['open' => null, 'close' => null, 'closed' => true],
                    ],
                    'default_language' => 'en',
                    'supported_languages' => ['en', 'sw'],
                    'subscription_plan' => 'enterprise',
                    'subscription_status' => 'active',
                    'is_active' => true,
                    'booking_payment_mode' => Hospital::BOOKING_PAYMENT_MODE_NONE,
                    'auto_confirm_paid_appointments' => true,
                    'reminder_enabled' => true,
                    'reminder_hours_before' => 24,
                    'settings' => [
                        'google_review_url' => 'https://g.page/r/demo-hospital/review',
                        'google_review_link' => 'https://g.page/r/demo-hospital/review',
                        'auto_confirm_paid_appointments' => true,
                    ],
                ])->save();
                app()->instance('currentHospital', $hospital);

                $admin = $this->saveUser($hospital, [
                    'name' => 'Demo Hospital Admin',
                    'email' => 'admin@demohospital.test',
                    'password' => 'DemoAdmin@2026!',
                    'role' => 'hospital_admin',
                    'is_admin' => true,
                    'is_doctor' => false,
                    'is_super_admin' => false,
                    'specialization' => null,
                    'phone' => '0700000000',
                    'bio' => null,
                ]);
                $admin->syncRolesFromFlags();

                $doctors = [
                    [
                        'name' => 'Dr. Amina Njeri',
                        'email' => 'amina.njeri@demohospital.test',
                        'specialization' => 'General Practitioner',
                        'phone' => '0700000001',
                        'consultation_fee' => 1500,
                        'licence_number' => 'KMPDC-DH-001',
                        'bio' => 'Dr. Amina Njeri provides comprehensive primary care for adults and families. She focuses on preventive health, accurate diagnosis, and clear patient education.',
                        'days' => [1, 2, 3, 4, 5],
                        'start' => '09:00',
                        'end' => '17:00',
                    ],
                    [
                        'name' => 'Dr. Brian Otieno',
                        'email' => 'brian.otieno@demohospital.test',
                        'specialization' => 'Dentist',
                        'phone' => '0700000002',
                        'consultation_fee' => 2500,
                        'licence_number' => 'KMPDC-DH-002',
                        'bio' => 'Dr. Brian Otieno provides preventive, restorative, and general dental care. He helps patients maintain long-term oral health through practical treatment plans.',
                        'days' => [1, 3, 5],
                        'start' => '08:00',
                        'end' => '16:00',
                    ],
                    [
                        'name' => 'Dr. Grace Wanjiku',
                        'email' => 'grace.wanjiku@demohospital.test',
                        'specialization' => 'Gynecologist/Obstetrician',
                        'phone' => '0700000003',
                        'consultation_fee' => 3000,
                        'licence_number' => 'KMPDC-DH-003',
                        'bio' => 'Dr. Grace Wanjiku supports women through reproductive health, pregnancy, and postnatal care. Her practice emphasizes respectful, evidence-based care.',
                        'days' => [2, 4, 6],
                        'start' => '09:00',
                        'end' => '16:00',
                    ],
                    [
                        'name' => 'Dr. Peter Kimani',
                        'email' => 'peter.kimani@demohospital.test',
                        'specialization' => 'Cardiologist',
                        'phone' => '0700000004',
                        'consultation_fee' => 6000,
                        'licence_number' => 'KMPDC-DH-004',
                        'bio' => 'Dr. Peter Kimani evaluates cardiovascular health and supports patients managing heart conditions. He combines careful assessment with individualized follow-up plans.',
                        'days' => [1, 3],
                        'start' => '10:00',
                        'end' => '15:00',
                    ],
                    [
                        'name' => 'Dr. Faith Mutua',
                        'email' => 'faith.mutua@demohospital.test',
                        'specialization' => 'Pediatrician',
                        'phone' => '0700000005',
                        'consultation_fee' => 2000,
                        'licence_number' => 'KMPDC-DH-005',
                        'bio' => 'Dr. Faith Mutua provides medical care for infants, children, and adolescents. She partners with families on wellness, development, and treatment.',
                        'days' => [1, 2, 3, 4, 5],
                        'start' => '08:00',
                        'end' => '12:00',
                    ],
                    [
                        'name' => 'Dr. Samuel Kiptoo',
                        'email' => 'samuel.kiptoo@demohospital.test',
                        'specialization' => 'Radiologist',
                        'phone' => '0700000006',
                        'consultation_fee' => 3500,
                        'licence_number' => 'KMPDC-DH-006',
                        'bio' => 'Dr. Samuel Kiptoo interprets diagnostic imaging and collaborates with clinical teams on patient care. He is committed to clear, timely, and accurate reporting.',
                        'days' => [1, 2, 3, 4, 5],
                        'start' => '08:00',
                        'end' => '17:00',
                    ],
                ];

                $doctorUsers = [];
                foreach ($doctors as $doctorData) {
                    $doctorAttributes = array_diff_key($doctorData, [
                        'days' => true,
                        'start' => true,
                        'end' => true,
                    ]);
                    $doctor = $this->saveUser($hospital, [
                        ...$doctorAttributes,
                        'password' => 'DemoDoctor@2026!',
                        'role' => 'doctor',
                        'is_admin' => false,
                        'is_doctor' => true,
                        'is_super_admin' => false,
                    ]);
                    $doctor->syncRolesFromFlags();
                    $doctorUsers[] = $doctor;

                    foreach ($doctorData['days'] as $day) {
                        DoctorAvailability::withoutGlobalScopes()->updateOrCreate(
                            [
                                'hospital_id' => $hospital->id,
                                'doctor_id' => $doctor->id,
                                'day_of_week' => $day,
                            ],
                            [
                                'start_time' => $doctorData['start'],
                                'end_time' => $doctorData['end'],
                                'slot_duration_minutes' => 30,
                                'max_patients_per_slot' => 2,
                                'is_active' => true,
                            ],
                        );
                    }
                }

                $services = [
                    ['General Consultation', 1500, 'General', 'General Practitioner', 30],
                    ['Dental Checkup & Cleaning', 2500, 'Dental', 'Dentist', 45],
                    ['Dental Extraction', 4000, 'Dental', 'Dentist', 45],
                    ['Orthodontics Consultation', 5000, 'Dental', 'Dentist', 45],
                    ['Optical Eye Checkup', 2000, 'Optical', 'Radiologist', 30],
                    ['CT Scan (Head/Chest/Abdomen)', 12000, 'Radiology', 'Radiologist', 45],
                    ['MRI Scan', 25000, 'Radiology', 'Radiologist', 60],
                    ['Digital X-Ray', 3500, 'Radiology', 'Radiologist', 30],
                    ['Ultrasound Scan (Obstetric)', 4500, 'Radiology', 'Gynecologist/Obstetrician', 45],
                    ['Ultrasound Scan (Abdominal)', 4000, 'Radiology', 'Radiologist', 45],
                    ['Physiotherapy Session', 3000, 'Physiotherapy', 'General Practitioner', 45],
                    ['Antenatal Clinic Visit', 1500, 'Maternity', 'Gynecologist/Obstetrician', 30],
                    ['Postnatal Care', 2000, 'Maternity', 'Gynecologist/Obstetrician', 30],
                    ['Family Planning Consultation', 1000, 'Maternity', 'Gynecologist/Obstetrician', 30],
                    ['Pediatrics Consultation', 2000, 'Pediatrics', 'Pediatrician', 30],
                    ['Dermatology Consultation', 3500, 'Specialist', 'General Practitioner', 30],
                    ['Cardiology Consultation', 6000, 'Cardiology', 'Cardiologist', 45],
                    ['ECG Test', 3000, 'Cardiology', 'Cardiologist', 30],
                    ['Laboratory Full Panel', 5500, 'Laboratory', 'General Practitioner', 30],
                    ['Renal Dialysis Session', 8500, 'Renal', 'General Practitioner', 240],
                    ['Vaccination (Various)', 1500, 'Vaccination', 'Pediatrician', 30],
                    ['Diabetes Screening', 2000, 'Screening', 'General Practitioner', 30],
                    ['Hypertension Screening', 1500, 'Screening', 'General Practitioner', 30],
                    ['Wellness Checkup Package', 8000, 'Wellness', 'General Practitioner', 60],
                ];
                $serviceModels = [];

                foreach ($services as [$name, $price, $category, $specialty, $duration]) {
                    $service = Service::withoutGlobalScopes()->updateOrCreate(
                        ['hospital_id' => $hospital->id, 'name' => $name],
                        [
                            'description' => $name.' at Demo Hospital.',
                            'price' => $price,
                            'category' => $category,
                            'requires_specialty' => $specialty,
                            'duration_minutes' => $duration,
                            'is_active' => true,
                        ],
                    );
                    $serviceModels[$name] = $service;
                }

                foreach ($this->knowledgeEntries() as $entry) {
                    KnowledgeBase::withoutGlobalScopes()->updateOrCreate(
                        [
                            'hospital_id' => $hospital->id,
                            'category' => $entry['category'],
                            'subcategory' => $entry['subcategory'],
                        ],
                        [
                            ...$entry,
                            'source' => 'Demo Hospital Reception',
                            'last_updated' => today(),
                        ],
                    );
                }

                $appointmentServices = [
                    'General Consultation',
                    'Dental Checkup & Cleaning',
                    'Antenatal Clinic Visit',
                    'Cardiology Consultation',
                    'Pediatrics Consultation',
                    'CT Scan (Head/Chest/Abdomen)',
                    'General Consultation',
                    'Dental Extraction',
                    'Postnatal Care',
                    'MRI Scan',
                ];

                foreach (range(0, 9) as $index) {
                    $doctorData = $doctors[$index % count($doctors)];
                    $doctor = $doctorUsers[$index % count($doctorUsers)];
                    $date = CarbonImmutable::today()->addDays($index + 1);
                    while (! in_array($date->dayOfWeek, $doctorData['days'], true)) {
                        $date = $date->addDay();
                    }
                    $slotStart = $doctorData['start'];
                    $slotEnd = CarbonImmutable::parse($slotStart)->addMinutes(30)->format('H:i');
                    $service = $serviceModels[$appointmentServices[$index]];

                    $bookingReference = 'DEMO-'.str_pad((string) ($index + 1), 5, '0', STR_PAD_LEFT);
                    $appointment = AppointmentRequest::withoutGlobalScopes()
                        ->where('hospital_id', $hospital->id)
                        ->where('booking_reference', $bookingReference)
                        ->first() ?? new AppointmentRequest;
                    $appointment->forceFill([
                        'hospital_id' => $hospital->id,
                        'booking_reference' => $bookingReference,
                        'session_id' => 'demo-seeder-appointment-'.$index,
                        'patient_id' => null,
                        'name' => 'Demo Patient '.($index + 1),
                        'phone' => '07000000'.str_pad((string) ($index + 11), 2, '0', STR_PAD_LEFT),
                        'email' => 'patient'.($index + 1).'@demohospital.test',
                        'preferred_date' => $date->toDateString(),
                        'reason' => 'Demo appointment for '.$service->name,
                        'raw_message' => 'Demo booking for '.$service->name,
                        'status' => AppointmentRequest::STATUS_CONFIRMED,
                        'payment_status' => 'not_required',
                        'payment_method' => 'none',
                        'payment_amount' => 0,
                        'booking_fee' => 0,
                        'service_id' => $service->id,
                        'doctor_id' => $doctor->id,
                        'slot_start_time' => $slotStart,
                        'slot_end_time' => $slotEnd,
                        'booking_source' => 'demo',
                        'confirmed_by_doctor_at' => now(),
                    ])->save();
                }
            });
        } finally {
            if ($hadCurrentHospital) {
                app()->instance('currentHospital', $previousHospital);
            } else {
                app()->forgetInstance('currentHospital');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function saveUser(Hospital $hospital, array $attributes): User
    {
        $user = User::withoutGlobalScopes()
            ->where('hospital_id', $hospital->id)
            ->where('email', $attributes['email'])
            ->first() ?? new User;

        $user->forceFill([
            ...$attributes,
            'hospital_id' => $hospital->id,
            'is_active' => true,
            'email_verified_at' => now(),
            'must_change_password' => false,
        ])->save();

        return $user;
    }

    /**
     * @return array<int, array{category: string, subcategory: string, language: string, keywords: array<int, string>, question: string, answer: string}>
     */
    private function knowledgeEntries(): array
    {
        $rows = [
            ['hours', 'hours-en', 'en', ['hours', 'opening', 'open'], 'What are your opening hours?', 'Demo Hospital is open Monday-Friday 8am-6pm and Saturday 9am-1pm. Emergency care is available 24/7.'],
            ['hours', 'hours-sw', 'sw', ['saa', 'wazi', 'kufungua'], 'Mnafanya kazi saa ngapi?', 'Demo Hospital iko wazi Jumatatu-Ijumaa saa 2 asubuhi hadi saa 12 jioni, na Jumamosi saa 3 asubuhi hadi saa 7 mchana. Huduma za dharura zinapatikana saa 24.'],
            ['location', 'location-en', 'en', ['location', 'address', 'where'], 'Where are you located?', 'We are at Nairobi CBD, Kenya, just off Moi Avenue.'],
            ['location', 'location-sw', 'sw', ['mahali', 'anwani', 'wapi'], 'Mko wapi?', 'Tuko Nairobi CBD, Kenya, karibu na Moi Avenue.'],
            ['parking', 'parking-en', 'en', ['parking', 'car', 'vehicle'], 'Is there parking?', 'Parking is available on the hospital grounds. Please ask reception about current availability.'],
            ['parking', 'parking-sw', 'sw', ['maegesho', 'gari'], 'Kuna maegesho?', 'Maegesho yanapatikana katika eneo la hospitali. Tafadhali uliza mapokezi kuhusu nafasi.'],
            ['insurance', 'insurance-en', 'en', ['sha', 'insurance', 'cover'], 'Do you accept SHA or insurance?', 'We accept SHA and participating insurance providers. Please bring your ID and insurance card so reception can confirm your cover.'],
            ['insurance', 'insurance-sw', 'sw', ['sha', 'bima'], 'Mnakubali SHA au bima?', 'Tunakubali SHA na baadhi ya watoa bima. Tafadhali leta kitambulisho na kadi ya bima ili mapokezi ithibitishe huduma zako.'],
            ['payment', 'payment-en', 'en', ['payment', 'pay', 'mpesa'], 'How can I pay?', 'Demo bookings do not require online payment. For in-person services, ask reception about accepted payment methods.'],
            ['payment', 'payment-sw', 'sw', ['malipo', 'kulipa', 'mpesa'], 'Ninaweza kulipa vipi?', 'Miadi ya maonyesho haihitaji malipo ya mtandaoni. Kwa huduma za hospitalini, uliza mapokezi kuhusu njia za malipo.'],
            ['services', 'services-en', 'en', ['services', 'treatments', 'offer'], 'What services do you offer?', 'We offer general consultations, dental care, maternity, paediatrics, cardiology, imaging, laboratory, screening, and wellness services.'],
            ['services', 'services-sw', 'sw', ['huduma', 'mnatoa'], 'Mnatoa huduma gani?', 'Tunatoa ushauri wa jumla, huduma za meno, uzazi, watoto, moyo, vipimo vya picha, maabara, uchunguzi na huduma za afya njema.'],
            ['doctors', 'doctors-en', 'en', ['doctors', 'staff', 'specialist'], 'Which doctors are available?', 'Our team includes general practice, dental, obstetrics and gynaecology, cardiology, paediatrics, and radiology clinicians. Availability is shown while booking.'],
            ['doctors', 'doctors-sw', 'sw', ['madaktari', 'daktari'], 'Mna madaktari gani?', 'Tuna madaktari wa jumla, meno, uzazi, moyo, watoto na radiolojia. Upatikanaji huonyeshwa wakati wa kuweka miadi.'],
            ['emergency', 'emergency-en', 'en', ['emergency', 'urgent', 'ambulance'], 'What should I do in an emergency?', 'Emergency services are available 24/7. Call 0700000000 or go to the nearest emergency department.'],
            ['emergency', 'emergency-sw', 'sw', ['dharura', 'haraka'], 'Nifanye nini wakati wa dharura?', 'Huduma za dharura zinapatikana saa 24. Piga simu 0700000000 au nenda kwenye idara ya dharura iliyo karibu.'],
            ['maternity', 'maternity-en', 'en', ['maternity', 'antenatal', 'pregnancy'], 'Do you offer maternity care?', 'Yes. We provide antenatal, postnatal, and family-planning consultations by appointment.'],
            ['maternity', 'maternity-sw', 'sw', ['uzazi', 'ujauzito', 'kliniki'], 'Mnatoa huduma za uzazi?', 'Ndiyo. Tunatoa huduma za kliniki ya wajawazito, baada ya kujifungua na uzazi wa mpango kwa miadi.'],
            ['dental', 'dental-en', 'en', ['dental', 'tooth', 'dentist'], 'Do you have dental services?', 'Yes. Dental checkups, cleaning, extraction, and orthodontics consultations are available by appointment.'],
            ['dental', 'dental-sw', 'sw', ['meno', 'daktari wa meno'], 'Mna huduma za meno?', 'Ndiyo. Uchunguzi na usafishaji wa meno, kung’oa jino na ushauri wa kupanga meno hupatikana kwa miadi.'],
            ['optical', 'optical-en', 'en', ['eye', 'optical', 'vision'], 'Do you provide eye checks?', 'Yes. Optical eye checkups are available. Please book an appointment for an assessment.'],
            ['optical', 'optical-sw', 'sw', ['macho', 'kuona'], 'Mnafanya uchunguzi wa macho?', 'Ndiyo. Uchunguzi wa afya ya macho unapatikana. Tafadhali weka miadi ya uchunguzi.'],
            ['radiology', 'radiology-en', 'en', ['x-ray', 'mri', 'ct', 'ultrasound'], 'What imaging services are available?', 'We offer CT, MRI, digital X-ray, and ultrasound appointments. A clinician may need to refer you.'],
            ['radiology', 'radiology-sw', 'sw', ['x-ray', 'mri', 'ultrasound', 'picha'], 'Mna vipimo gani vya picha?', 'Tunatoa miadi ya CT, MRI, X-ray ya kidijitali na ultrasound. Huenda ukahitaji rufaa ya daktari.'],
            ['laboratory', 'laboratory-en', 'en', ['lab', 'laboratory', 'results'], 'How do I get my lab results?', 'Ask reception or the laboratory team for result collection instructions and expected turnaround time.'],
            ['laboratory', 'laboratory-sw', 'sw', ['maabara', 'matokeo'], 'Nitapataje matokeo ya maabara?', 'Uliza mapokezi au timu ya maabara kuhusu jinsi ya kuchukua matokeo na muda wa kuyapata.'],
            ['pharmacy', 'pharmacy-en', 'en', ['pharmacy', 'medicine', 'drugs'], 'Do you have a pharmacy?', 'Please ask reception about pharmacy services and operating hours during your visit.'],
            ['pharmacy', 'pharmacy-sw', 'sw', ['duka la dawa', 'dawa'], 'Mna duka la dawa?', 'Tafadhali uliza mapokezi kuhusu huduma na saa za duka la dawa unapofika.'],
            ['appointments', 'appointments-en', 'en', ['appointment', 'book', 'booking'], 'How do I book an appointment?', 'Use this chat to choose a service, doctor, date, and available time, then provide your name and phone number.'],
            ['appointments', 'appointments-sw', 'sw', ['miadi', 'kuweka nafasi'], 'Ninawekaje miadi?', 'Tumia gumzo hili kuchagua huduma, daktari, tarehe na muda unaopatikana, kisha weka jina na nambari yako ya simu.'],
            ['cancellation', 'cancellation-en', 'en', ['cancel', 'cancellation'], 'How do I cancel an appointment?', 'Contact reception at 0700000000 with your booking reference to request a cancellation.'],
            ['cancellation', 'cancellation-sw', 'sw', ['kughairi', 'futa miadi'], 'Ninafutaje miadi?', 'Wasiliana na mapokezi kwa 0700000000 ukitumia nambari ya kumbukumbu ya miadi yako.'],
            ['rescheduling', 'rescheduling-en', 'en', ['reschedule', 'change appointment'], 'Can I reschedule my appointment?', 'Yes. Call 0700000000 with your booking reference, preferably at least 24 hours in advance.'],
            ['rescheduling', 'rescheduling-sw', 'sw', ['badilisha miadi', 'ahirisha'], 'Ninaweza kubadilisha miadi?', 'Ndiyo. Piga simu 0700000000 ukiwa na nambari ya miadi, ikiwezekana saa 24 kabla.'],
            ['records', 'records-en', 'en', ['records', 'medical record', 'file'], 'How can I request my medical records?', 'Please contact reception and bring identification. Records are provided only after identity and authorization checks.'],
            ['records', 'records-sw', 'sw', ['rekodi', 'faili la afya'], 'Ninaombaje rekodi zangu za afya?', 'Wasiliana na mapokezi na ulete kitambulisho. Rekodi hutolewa baada ya kuthibitisha utambulisho na ruhusa.'],
            ['billing', 'billing-en', 'en', ['bill', 'invoice', 'billing'], 'How can I ask about my bill?', 'Please contact reception or the billing desk with your visit date and receipt or booking reference.'],
            ['billing', 'billing-sw', 'sw', ['bili', 'ankara', 'malipo'], 'Ninaulizaje kuhusu bili yangu?', 'Wasiliana na mapokezi au kitengo cha bili ukiwa na tarehe ya ziara na risiti au nambari ya miadi.'],
            ['visiting', 'visiting-en', 'en', ['visiting hours', 'visitors', 'visit'], 'What are the visiting hours?', 'Please contact reception at 0700000000 to confirm current visiting hours and any ward-specific guidance.'],
            ['visiting', 'visiting-sw', 'sw', ['saa za kutembelea', 'wageni'], 'Saa za kutembelea wagonjwa ni zipi?', 'Wasiliana na mapokezi kwa 0700000000 kuthibitisha saa za kutembelea na masharti ya wodi husika.'],
            ['discharge', 'discharge-en', 'en', ['discharge', 'going home'], 'What happens when I am discharged?', 'Your care team will explain your medication, follow-up plan, and any warning signs before you leave. Ask them if anything is unclear.'],
            ['discharge', 'discharge-sw', 'sw', ['kuruhusiwa', 'kwenda nyumbani'], 'Nini hufanyika nikiruhusiwa?', 'Timu yako ya matibabu itaeleza dawa, mpango wa ufuatiliaji na dalili za tahadhari kabla hujaondoka. Uliza ikiwa jambo haliko wazi.'],
            ['admission', 'admission-en', 'en', ['admission', 'admit', 'inpatient'], 'How do I arrange admission?', 'A clinician must assess and authorize admission. Reception can explain the process and documents required.'],
            ['admission', 'admission-sw', 'sw', ['kulazwa', 'hospitali'], 'Ninawezaje kupanga kulazwa?', 'Daktari lazima akuchunguze na kuidhinisha kulazwa. Mapokezi yanaweza kueleza utaratibu na nyaraka zinazohitajika.'],
        ];

        return array_map(static fn (array $row): array => [
            'category' => $row[0],
            'subcategory' => $row[1],
            'language' => $row[2],
            'keywords' => $row[3],
            'question' => $row[4],
            'answer' => $row[5],
        ], $rows);
    }
}
