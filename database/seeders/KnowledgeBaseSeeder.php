<?php

namespace Database\Seeders;

use App\Models\Hospital;
use App\Models\KnowledgeBase;
use Illuminate\Database\Seeder;

class KnowledgeBaseSeeder extends Seeder
{
    public function run(): void
    {
        $pearl = Hospital::query()->where('slug', 'pearl')->firstOrFail();
        app()->instance('currentHospital', $pearl);
        $hospitalName = (string) $pearl->name;
        $assistantName = $pearl->chatbotName();
        $hospitalLocation = (string) $pearl->address;
        $hospitalEmail = (string) $pearl->email;
        $appointmentPhone = (string) $pearl->phone;
        $emergencyPhone = (string) $pearl->emergency_phone;
        $emergencyHours = (string) $pearl->hours_emergency;
        $outpatientHours = (string) $pearl->hours_outpatient;
        $website = (string) $pearl->website;
        $depositAmount = number_format((float) $pearl->deposit_amount, 0);
        $source = $hospitalName.' Administration';

        $records = [
            [
                'category' => 'services',
                'subcategory' => 'services_overview',
                'keywords' => ['services', 'what services do you offer', 'what services are available', 'service list', 'offer services', 'all services', 'services offered', 'huduma', 'huduma zenu'],
                'question' => 'What services do you offer?',
                'answer' => $hospitalName.' offers outpatient care, inpatient care, surgery, dialysis, oncology, IVF and fertility care, specialist clinics, physiotherapy, radiology and imaging, laboratory services, maternity and child healthcare, family planning, wellness screening, dental, optical, pharmacy, and emergency services.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'outpatient',
                'keywords' => ['outpatient', 'opd', 'clinic', 'consultation', 'general doctor', 'triage', 'clinical officer', 'walk-in'],
                'question' => 'What outpatient services are available?',
                'answer' => 'Out-patient hours: '.$outpatientHours.'. Services include general consultations and triage; call '.$appointmentPhone.' to confirm current availability.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'inpatient',
                'keywords' => ['inpatient', 'admission', 'ward', 'admitted', 'stay', 'bed', 'nursing'],
                'question' => 'What inpatient services are available?',
                'answer' => 'Comprehensive admission care with modern comfortable wards and 24-hour dedicated nursing monitoring.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'surgery',
                'keywords' => ['surgery', 'surgical', 'theatre', 'operation', 'theater', 'minor surgery', 'major surgery', 'obstetric surgery'],
                'question' => 'What surgical services are available?',
                'answer' => $hospitalName.' Surgical Services include minor, major, elective, emergency, and obstetric surgeries in a fully equipped theatre. Call '.$appointmentPhone.' to confirm service availability.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'dialysis',
                'keywords' => ['dialysis', 'hemodialysis', 'kidney treatment', 'kidney', 'renal', 'renal failure', 'kidney failure', 'figo'],
                'question' => 'What dialysis care is available?',
                'answer' => $hospitalName.' Dialysis Care Unit provides hemodialysis sessions, regular lab monitoring, and pre-dialysis screening. Contact '.$appointmentPhone.' to schedule.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'oncology',
                'keywords' => ['oncology', 'cancer', 'chemotherapy', 'chemo', 'tumor', 'cancer treatment', 'staging', 'saratani'],
                'question' => 'What oncology services are available?',
                'answer' => $hospitalName.' Oncology Services provide cancer care including diagnosis, staging, chemotherapy administration, and supportive care. Contact '.$appointmentPhone.' for service information.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'ivf-fertility',
                'keywords' => ['ivf', 'fertility', 'infertile', 'conceive', 'pregnancy help', 'reproductive', 'fertility clinic'],
                'question' => 'What IVF and fertility services are available?',
                'answer' => $hospitalName.' IVF and Fertility Clinic offers confidential fertility diagnostics, reproductive assistance, and counseling. Contact '.$appointmentPhone.' to enquire or book.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'specialist-clinics',
                'keywords' => ['specialist', 'consultant', 'physician', 'surgeon', 'oncologist', 'gynecologist', 'obstetrician', 'pediatrician', 'orthopedics', 'urologist', 'ENT', 'psychiatrist'],
                'question' => 'Which specialist consultant clinics are available?',
                'answer' => $hospitalName.' hosts specialist clinics for Physicians, Surgeons, Oncologists, OB/GYNs, Pediatricians, Orthopedic Surgeons, Urologists, ENT, and Psychiatrists. Call '.$appointmentPhone.' to confirm a specialist clinic day.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'physiotherapy',
                'keywords' => ['physiotherapy', 'physio', 'physical therapy', 'rehab', 'rehabilitation'],
                'question' => 'What physiotherapy services are available?',
                'answer' => $hospitalName.' Physiotherapy Unit provides physical therapy and rehabilitation to support recovery and mobility. Call '.$appointmentPhone.' to confirm availability.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'ct-scan',
                'keywords' => ['ct scan', 'ct', 'ctpa', 'ct pulmonary', 'ct angiography', 'head scan', 'chest scan', 'pelvis scan', 'abdomen scan'],
                'question' => 'What CT scan services are available?',
                'answer' => $hospitalName.' Radiology and Imaging offers CT scans, digital X-rays, ultrasound, MRI, mammography, and fluoroscopy. Contact '.$appointmentPhone.' to confirm which services are currently available.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'xray-fluoroscopy',
                'keywords' => ['x-ray', 'xray', 'fluoroscopy', 'hsg', 'mcu', 'barium', 'dye imaging'],
                'question' => 'What X-ray and fluoroscopy services are available?',
                'answer' => $hospitalName.' offers digital X-rays and fluoroscopy. Call '.$appointmentPhone.' to confirm available imaging and make arrangements.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'ultrasound',
                'keywords' => ['ultrasound', 'scan', 'sonography', 'doppler', 'color doppler', '3d scan', '4d scan', 'pregnancy scan'],
                'question' => 'What ultrasound scanning services are available?',
                'answer' => $hospitalName.' provides ultrasound imaging, including 2D, 3D/4D, and Doppler scans. Contact '.$appointmentPhone.' to confirm availability.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'biopsies',
                'keywords' => ['biopsy', 'fna', 'fine needle aspiration', 'core biopsy', 'tissue biopsy'],
                'question' => 'What biopsy services are available?',
                'answer' => $hospitalName.' provides biopsy services. Contact '.$appointmentPhone.' to confirm the available biopsy procedures and booking arrangements.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'cardio-neuro-diagnostics',
                'keywords' => ['ecg', 'ekg', 'echo', 'echocardiogram', 'eeg', 'electrocardiogram', 'electroencephalogram', 'heart test', 'brain test'],
                'question' => 'What cardio and neuro diagnostic tests are available?',
                'answer' => $hospitalName.' offers ECG, ECHO, and EEG diagnostic tests. Contact '.$appointmentPhone.' to confirm availability.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'endoscopy-colonoscopy',
                'keywords' => ['endoscopy', 'colonoscopy', 'gastroscopy', 'camera test', 'gi exam'],
                'question' => 'What endoscopy services are available?',
                'answer' => $hospitalName.' offers endoscopy and colonoscopy services. Contact '.$appointmentPhone.' to confirm availability and preparation instructions.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'laboratory',
                'keywords' => ['lab', 'laboratory', 'blood test', 'urine test', 'test', 'hematology', 'biochemistry', 'diagnostics'],
                'question' => 'What laboratory services are available?',
                'answer' => $hospitalName.' Laboratory offers hematology, biochemistry, microbiology, serology, urinalysis, and histopathology. Contact '.$appointmentPhone.' to confirm test availability and result timelines.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'maternity-child-health',
                'keywords' => ['maternity', 'antenatal', 'prenatal', 'delivery', 'postnatal', 'baby clinic', 'well-baby', 'maternal', 'pregnancy'],
                'question' => 'What maternity and child healthcare services are available?',
                'answer' => $hospitalName.' Maternity and Child Healthcare includes antenatal clinics, delivery care, postnatal support, and well-baby clinics. Contact '.$appointmentPhone.' for details.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'family-planning',
                'keywords' => ['family planning', 'contraception', 'birth control', 'reproductive health'],
                'question' => 'What family planning services are available?',
                'answer' => $hospitalName.' offers family-planning counseling and contraception options. Contact '.$appointmentPhone.' for details.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'wellness-screening',
                'keywords' => ['wellness', 'screening', 'check-up', 'annual checkup', 'well-woman', 'well-man'],
                'question' => 'What wellness and screening services are available?',
                'answer' => $hospitalName.' offers routine wellness screenings, including Well-Woman and Well-Man check-ups. Contact '.$appointmentPhone.' to confirm available screening services.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'dental',
                'keywords' => ['dental', 'dentist', 'teeth', 'tooth', 'cleaning', 'extraction', 'filling', 'cosmetic dental', 'mno', 'menoni'],
                'question' => 'What dental services are available?',
                'answer' => $hospitalName.' Dental Unit offers routine check-ups, cleaning and scaling, cosmetic procedures, extractions, fillings for adults and children, and emergency dental care. To book, call '.$appointmentPhone.' or ask me to book a dental appointment.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'optical',
                'keywords' => ['optical', 'eye', 'vision', 'glasses', 'eyewear', 'glaucoma', 'cataract', 'eye test'],
                'question' => 'What optical services are available?',
                'answer' => $hospitalName.' Optical Clinic offers vision tests, glaucoma and cataract screening, and prescription eyewear. Contact '.$appointmentPhone.' to confirm availability.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'emergency',
                'keywords' => ['emergency', 'urgent', 'ambulance', 'accident', 'casualty', '24 hour', 'dharura'],
                'question' => 'What emergency services are available?',
                'answer' => $hospitalName.' provides emergency services. Emergency hours: '.$emergencyHours.'. Call '.$emergencyPhone.' immediately for urgent help.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'radiology',
                'keywords' => ['radiology', 'x-ray', 'xray', 'ultrasound', 'scan', 'imaging', 'ct', 'ct scan', 'fluoroscopy'],
                'question' => 'What imaging services are available at '.$hospitalName.'?',
                'answer' => $hospitalName.' Radiology and Imaging includes Digital X-Ray, Ultrasound (2D/3D/4D), CT Scan, MRI, Mammography, and Fluoroscopy. Contact '.$appointmentPhone.' to confirm current availability.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'pharmacy',
                'keywords' => ['pharmacy', 'medication', 'medicine', 'prescription', 'chemist'],
                'question' => 'Is pharmacy service available?',
                'answer' => 'Please call '.$appointmentPhone.' to ask whether pharmacy service is currently available at '.$hospitalName.'.',
            ],
            [
                'category' => 'location',
                'subcategory' => 'contact',
                'keywords' => ['contact', 'phone', 'phone number', 'call', 'reach', 'whatsapp', 'email', 'telephone'],
                'question' => 'How can I contact '.$hospitalName.'?',
                'answer' => 'Phone and WhatsApp: '.$appointmentPhone.'. Email: '.$hospitalEmail.'. Location: '.$hospitalLocation.'. Website: '.$website.'.',
            ],
            [
                'category' => 'location',
                'subcategory' => 'parking',
                'keywords' => ['parking', 'car park', 'vehicle', 'where to park'],
                'question' => 'Is parking available at '.$hospitalName.'?',
                'answer' => 'Please call '.$appointmentPhone.' for current parking arrangements and directions.',
            ],
            [
                'category' => 'doctors',
                'subcategory' => 'general',
                'keywords' => ['doctors', 'specialist', 'physician', 'surgeon', 'consultant', 'medical staff', 'doctor schedule'],
                'question' => 'Which specialists are available at '.$hospitalName.'?',
                'answer' => $hospitalName.' hosts specialist clinics for Physicians, Surgeons, Oncologists, OB/GYNs, Pediatricians, Orthopedic Surgeons, Urologists, ENT, and Psychiatrists. Call '.$appointmentPhone.' to confirm a specialist clinic day.',
            ],
            [
                'category' => 'hours',
                'subcategory' => 'outpatient',
                'keywords' => ['hours', 'open', 'opening', 'time', 'when', 'closed', 'working hours', 'outpatient hours'],
                'question' => 'What are the opening hours at '.$hospitalName.'?',
                'answer' => 'Emergency hours: '.$emergencyHours.'. Routine outpatient hours: '.$outpatientHours.'. Call '.$appointmentPhone.' to confirm clinic availability.',
            ],
            [
                'category' => 'location',
                'subcategory' => 'address',
                'keywords' => ['location', 'where', 'address', 'directions', 'maps', 'located', 'find you'],
                'question' => 'Where is '.$hospitalName.' located?',
                'answer' => $hospitalName.' is located at '.$hospitalLocation.'.',
            ],
            [
                'category' => 'appointments',
                'subcategory' => 'booking',
                'keywords' => ['appointment', 'book', 'schedule', 'consultation', 'book appointment', 'schedule appointment', 'see a doctor', 'visit doctor'],
                'question' => 'How do I book an appointment at '.$hospitalName.'?',
                'answer' => 'I can help you request an appointment. Please share your full name, phone number, preferred date, the service you need, and your preferred time if you have one. You can also call '.$appointmentPhone.'.',
            ],
            [
                'category' => 'appointments',
                'subcategory' => 'cancellation',
                'keywords' => ['cancel appointment', 'change appointment', 'reschedule', 'appointment cancellation', 'cancel booking'],
                'question' => 'How can I cancel or reschedule an appointment?',
                'answer' => 'To cancel or reschedule an appointment, please call '.$appointmentPhone.' so the team can assist you.',
            ],
            [
                'category' => 'payment',
                'subcategory' => 'methods',
                'keywords' => ['payment', 'pay', 'cost', 'fees', 'deposit', 'price', 'charges', 'medical bill'],
                'question' => 'How do I pay an appointment deposit?',
                'answer' => 'The appointment deposit is KSh '.$depositAmount.'. For an appointment booked through '.$assistantName.', an M-Pesa payment prompt is sent to your phone. Call '.$appointmentPhone.' for other payment questions.',
            ],
            [
                'category' => 'payment',
                'subcategory' => 'insurance',
                'keywords' => ['insurance', 'cover', 'accepted insurance', 'health insurance', 'NHIF', 'SHA'],
                'question' => 'Which insurance providers are accepted?',
                'answer' => 'Please call '.$appointmentPhone.' to confirm current insurance coverage before your visit.',
            ],
            [
                'category' => 'visiting',
                'subcategory' => 'general',
                'keywords' => ['visiting hours', 'visit patient', 'visitors', 'family', 'visiting time'],
                'question' => 'What are the hospital visiting hours?',
                'answer' => 'Please call '.$appointmentPhone.' for current visiting guidance.',
            ],
            [
                'category' => 'amenities',
                'subcategory' => 'food',
                'keywords' => ['food', 'cafeteria', 'restaurant', 'meal', 'canteen'],
                'question' => 'Is there a cafeteria?',
                'answer' => 'Please call '.$appointmentPhone.' for current cafeteria and meal information.',
            ],
            [
                'category' => 'resources',
                'subcategory' => 'admission',
                'keywords' => ['admission', 'admitted', 'inpatient', 'what to bring', 'patient admission'],
                'question' => 'How can I get information about admission?',
                'answer' => 'For admission information and requirements, please contact '.$hospitalName.' at '.$appointmentPhone.'.',
            ],
            [
                'category' => 'resources',
                'subcategory' => 'discharge',
                'keywords' => ['discharge', 'leave hospital', 'going home', 'discharge process'],
                'question' => 'How can I get information about discharge?',
                'answer' => 'Please speak with your care team for discharge instructions and follow-up arrangements.',
            ],
            [
                'category' => 'general',
                'subcategory' => 'feedback',
                'keywords' => ['feedback', 'complaint', 'suggestion', 'praise', 'report a problem'],
                'question' => 'How can I provide feedback?',
                'answer' => 'You can share feedback with '.$hospitalName.' by calling '.$appointmentPhone.' or emailing '.$hospitalEmail.'.',
            ],
            [
                'category' => 'doctors',
                'subcategory' => 'availability',
                'keywords' => ['doctor availability', 'available appointment times', 'doctor slots', 'when can i see a doctor', 'available doctors'],
                'question' => 'How can I check doctor availability?',
                'answer' => 'Tell me the date you would like to visit and, if you have a preference, the doctor or specialty. I can check available appointment times.',
            ],
            [
                'category' => 'greeting',
                'subcategory' => 'hello',
                'keywords' => ['hello', 'hi', 'hey', 'greetings', 'good morning', 'good afternoon', 'good evening', 'how are you'],
                'question' => 'Greeting',
                'answer' => 'Hello! I’m '.$assistantName.', '.$hospitalName.'’s healthcare assistant. How can I help you today?',
            ],
            [
                'category' => 'greeting',
                'subcategory' => 'swahili',
                'keywords' => ['habari', 'hujambo', 'sijambo', 'jambo', 'mambo', 'vipi', 'niaje', 'sasa', 'shikamoo', 'salama', 'poa'],
                'question' => 'Salamu kwa Kiswahili',
                'answer' => 'Habari! Mimi ni '.$assistantName.', msaidizi wa afya wa '.$hospitalName.'. Naweza kukusaidia vipi leo?',
            ],
            [
                'category' => 'location',
                'subcategory' => 'swahili',
                'keywords' => ['mko wapi', 'mahali', 'anwani', 'wapi'],
                'question' => 'Hospitali iko wapi?',
                'answer' => $this->swahiliHospitalName($hospitalName).' iko '.$hospitalLocation.'.',
            ],
            [
                'category' => 'hours',
                'subcategory' => 'swahili',
                'keywords' => ['mnafanya kazi saa ngapi', 'saa za kazi', 'muda'],
                'question' => 'Hospitali inafunguliwa saa ngapi?',
                'answer' => 'Saa za dharura: '.$emergencyHours.'. Saa za wagonjwa wa nje: '.$outpatientHours.'. Piga '.$appointmentPhone.' kuthibitisha upatikanaji wa huduma.',
            ],
            [
                'category' => 'appointments',
                'subcategory' => 'swahili',
                'keywords' => ['kuweka miadi', 'miadi', 'naomba miadi', 'miadi ya daktari'],
                'question' => 'Ninawezaje kuweka miadi?',
                'answer' => 'Naweza kukusaidia kuweka miadi. Tafadhali nipe jina lako kamili, namba ya simu, tarehe unayopendelea, na huduma unayohitaji.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'emergency-swahili',
                'keywords' => ['dharura', 'maumivu ya kifua', 'shida kupumua', 'damu nyingi', 'kiharusi', 'haraka', 'ajali', 'nimeumia vibaya', 'msaada wa haraka', 'msada wa haraka'],
                'question' => 'Nifanye nini wakati wa dharura?',
                'answer' => 'Kwa dharura, piga '.$emergencyPhone.' sasa. Ninakuunganisha na mhudumu wa afya.',
            ],
            [
                'category' => 'location',
                'subcategory' => 'contact-swahili',
                'keywords' => ['namba ya simu', 'wasiliana', 'piga simu', 'barua pepe'],
                'question' => 'Ninawezaje kuwasiliana na hospitali?',
                'answer' => 'Unaweza kuwasiliana nasi kwa '.$appointmentPhone.' (simu na WhatsApp) au barua pepe '.$hospitalEmail.'.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'complete-service-list-swahili',
                'keywords' => ['huduma', 'mnafanya nini', 'huduma zenu'],
                'question' => 'Hospitali inatoa huduma gani?',
                'answer' => $hospitalName.' inatoa huduma za wagonjwa wa nje, kulazwa, upasuaji, dialysis, oncology, IVF na uzazi, kliniki za wataalamu, physiotherapy, radiology na imaging, maabara, uzazi na afya ya watoto, family planning, uchunguzi wa afya, huduma za meno na macho, pharmacy, na huduma za dharura.',
            ],
        ];

        foreach ($records as $record) {
            $identity = [
                'category' => $record['category'],
                'subcategory' => $record['subcategory'],
            ];
            $attributes = [
                'keywords' => json_encode($record['keywords'], JSON_THROW_ON_ERROR),
                'question' => $record['question'],
                'answer' => $record['answer'],
                'source' => $source,
                'last_updated' => now(),
            ];
            $existingRecords = KnowledgeBase::query()
                ->where($identity)
                ->orderBy('id')
                ->get();

            if ($existingRecords->isEmpty()) {
                KnowledgeBase::query()->create([...$identity, ...$attributes]);

                continue;
            }

            $existingRecords->first()->update($attributes);
            $existingRecords->slice(1)->each->delete();
        }
    }

    private function swahiliHospitalName(string $hospitalName): string
    {
        return 'Hospitali ya '.$hospitalName;
    }
}
