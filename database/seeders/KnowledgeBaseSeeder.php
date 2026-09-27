<?php

namespace Database\Seeders;

use App\Models\KnowledgeBase;
use Illuminate\Database\Seeder;

class KnowledgeBaseSeeder extends Seeder
{
    public function run(): void
    {
        $pearl = \App\Models\Hospital::query()->where('slug', 'pearl')->firstOrFail();
        app()->instance('currentHospital', $pearl);
        $hospitalName = (string) pearlie_config('hospital.name');
        $hospitalLocation = (string) pearlie_config('hospital.location');
        $hospitalEmail = (string) pearlie_config('hospital.email');
        $appointmentPhone = (string) pearlie_config('hospital.appointment_phone');
        $emergencyPhone = (string) pearlie_config('hospital.emergency_phone');
        $website = (string) pearlie_config('hospital.website');
        $depositAmount = number_format((float) pearlie_config('appointment.deposit_amount'), 0);
        $source = $hospitalName.' Administration';

        $records = [
            [
                'category' => 'services',
                'subcategory' => 'outpatient',
                'keywords' => ['outpatient', 'opd', 'clinic', 'consultation', 'general doctor', 'triage', 'clinical officer', 'walk-in'],
                'question' => 'What outpatient services are available?',
                'answer' => 'Out-patient services are available 24/7. Includes general medical officer consultations, triage, clinical officer check-ups, and emergency treatments.',
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
                'answer' => 'Fully functional, state-of-the-art operating theater equipped for minor, major, elective, emergency, and obstetric surgeries.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'dialysis',
                'keywords' => ['dialysis', 'hemodialysis', 'kidney', 'renal', 'renal failure', 'kidney failure'],
                'question' => 'What dialysis care is available?',
                'answer' => 'Quality hemodialysis sessions with regular monthly lab monitoring and pre-dialysis screenings.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'oncology',
                'keywords' => ['oncology', 'cancer', 'chemotherapy', 'chemo', 'tumor', 'cancer treatment', 'staging'],
                'question' => 'What oncology services are available?',
                'answer' => 'Comprehensive cancer care including diagnosis, staging, and chemotherapy administration.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'ivf-fertility',
                'keywords' => ['ivf', 'fertility', 'infertile', 'conceive', 'pregnancy help', 'reproductive', 'fertility clinic'],
                'question' => 'What IVF and fertility services are available?',
                'answer' => 'Confidential, customized fertility clinics supporting reproductive assistance, diagnostics, and management for couples.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'specialist-clinics',
                'keywords' => ['specialist', 'consultant', 'physician', 'surgeon', 'oncologist', 'gynecologist', 'obstetrician', 'pediatrician', 'orthopedics', 'urologist', 'ENT', 'psychiatrist'],
                'question' => 'Which specialist consultant clinics are available?',
                'answer' => 'Regular clinics throughout the week with specialists including Physicians, Surgeons, Oncologists, Obstetricians & Gynecologists, Pediatricians, Orthopedic Surgeons, Urologists, ENT Specialists, and Psychiatrists.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'physiotherapy',
                'keywords' => ['physiotherapy', 'physio', 'physical therapy', 'rehab', 'rehabilitation'],
                'question' => 'What physiotherapy services are available?',
                'answer' => 'Physical therapy and rehabilitation care to assist recovery and improve movement.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'ct-scan',
                'keywords' => ['ct scan', 'ct', 'ctpa', 'ct pulmonary', 'ct angiography', 'head scan', 'chest scan', 'pelvis scan', 'abdomen scan'],
                'question' => 'What CT scan services are available?',
                'answer' => 'High-definition internal imaging including head, chest, pelvic, abdomen, and CT Pulmonary Angiogram (CTPA) scans.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'xray-fluoroscopy',
                'keywords' => ['x-ray', 'xray', 'fluoroscopy', 'hsg', 'mcu', 'barium', 'dye imaging'],
                'question' => 'What X-ray and fluoroscopy services are available?',
                'answer' => 'Regular digital X-rays plus specialized dye-guided imaging like HSG (Hysterosalpingography), MCU, and Barium studies.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'ultrasound',
                'keywords' => ['ultrasound', 'scan', 'sonography', 'doppler', 'color doppler', '3d scan', '4d scan', 'pregnancy scan'],
                'question' => 'What ultrasound scanning services are available?',
                'answer' => 'Real-time imaging by a consultant radiologist including color Doppler and advanced 3D/4D obstetric scans.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'biopsies',
                'keywords' => ['biopsy', 'fna', 'fine needle aspiration', 'core biopsy', 'tissue biopsy'],
                'question' => 'What biopsy services are available?',
                'answer' => 'Fine Needle Aspiration (FNA) and core tissue biopsies under ultrasound or CT guidance.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'cardio-neuro-diagnostics',
                'keywords' => ['ecg', 'ekg', 'echo', 'echocardiogram', 'eeg', 'electrocardiogram', 'electroencephalogram', 'heart test', 'brain test'],
                'question' => 'What cardio and neuro diagnostic tests are available?',
                'answer' => 'Includes Electrocardiograms (ECG), Echocardiograms (ECHO), and Electroencephalograms (EEG).',
            ],
            [
                'category' => 'services',
                'subcategory' => 'endoscopy-colonoscopy',
                'keywords' => ['endoscopy', 'colonoscopy', 'gastroscopy', 'camera test', 'gi exam'],
                'question' => 'What endoscopy services are available?',
                'answer' => 'Internal gastrointestinal examinations using specialized diagnostic cameras.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'laboratory',
                'keywords' => ['lab', 'laboratory', 'blood test', 'urine test', 'test', 'hematology', 'biochemistry', 'diagnostics'],
                'question' => 'What laboratory services are available?',
                'answer' => 'Fully automated modern laboratory performing accurate, timely hematology, biochemistry, and general diagnostic tests.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'maternity-child-health',
                'keywords' => ['maternity', 'antenatal', 'prenatal', 'delivery', 'postnatal', 'baby clinic', 'well-baby', 'maternal', 'pregnancy'],
                'question' => 'What maternity and child healthcare services are available?',
                'answer' => 'Dedicated prenatal check-ups, safe delivery wards, postnatal support, and routine infant well-baby clinics.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'family-planning',
                'keywords' => ['family planning', 'contraception', 'birth control', 'reproductive health'],
                'question' => 'What family planning services are available?',
                'answer' => 'Comprehensive counseling, advice, and delivery of alternative reproductive health options.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'wellness-screening',
                'keywords' => ['wellness', 'screening', 'check-up', 'annual checkup', 'well-woman', 'well-man'],
                'question' => 'What wellness and screening services are available?',
                'answer' => 'Standard routine check-ups including comprehensive annual Well-Woman and Well-Man health screenings.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'dental',
                'keywords' => ['dental', 'dentist', 'teeth', 'tooth', 'cleaning', 'extraction', 'filling', 'cosmetic dental'],
                'question' => 'What dental services are available?',
                'answer' => 'Two active dental rooms delivering routine check-ups, cleaning, cosmetic procedures, extractions, and fillings for adults and children.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'optical',
                'keywords' => ['optical', 'eye', 'vision', 'glasses', 'eyewear', 'glaucoma', 'cataract', 'eye test'],
                'question' => 'What optical services are available?',
                'answer' => 'Essential vision care, glaucoma/cataract screenings, and prescription eyewear fitting by eye care experts.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'emergency',
                'keywords' => ['emergency', 'urgent', 'ambulance', 'accident', 'casualty', '24 hour'],
                'question' => 'What emergency services are available?',
                'answer' => '24/7 emergency services. For emergencies call '.$emergencyPhone.' immediately.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'general',
                'keywords' => ['services', 'departments', 'what services', 'what do you offer', 'what does the hospital do', 'hospital services'],
                'question' => 'What services does '.$hospitalName.' offer?',
                'answer' => 'Services include out-patient and in-patient care, surgery, dialysis, oncology, IVF and fertility care, specialist consultant clinics, physiotherapy, CT scans, X-ray and fluoroscopy, ultrasound, biopsies, ECG/ECHO/EEG diagnostics, endoscopy and colonoscopy, laboratory services, maternity and child healthcare, family planning, wellness screening, dental care, optical care, and emergency services.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'radiology',
                'keywords' => ['radiology', 'x-ray', 'xray', 'ultrasound', 'scan', 'imaging', 'ct', 'ct scan', 'fluoroscopy'],
                'question' => 'What imaging services are available at '.$hospitalName.'?',
                'answer' => 'Imaging services include CT scans, digital X-rays and fluoroscopy, ultrasound with color Doppler and 3D/4D obstetric scans, and image-guided biopsies. Contact '.$appointmentPhone.' to confirm arrangements.',
            ],
            [
                'category' => 'services',
                'subcategory' => 'pharmacy',
                'keywords' => ['pharmacy', 'medication', 'medicine', 'prescription', 'chemist'],
                'question' => 'Is pharmacy service available?',
                'answer' => 'Please call '.$appointmentPhone.' for current pharmacy service information.',
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
                'answer' => 'Specialist clinics run during the week and include Physicians, Surgeons, Oncologists, Obstetricians & Gynecologists, Pediatricians, Orthopedic Surgeons, Urologists, ENT Specialists, and Psychiatrists. Call '.$appointmentPhone.' to confirm clinic days.',
            ],
            [
                'category' => 'hours',
                'subcategory' => 'outpatient',
                'keywords' => ['hours', 'open', 'opening', 'time', 'when', 'closed', 'working hours', 'outpatient hours'],
                'question' => 'What are the opening hours at '.$hospitalName.'?',
                'answer' => 'Emergency and out-patient services are available '.$this->emergencyHours().'. Specialist clinics run during the week; call '.$appointmentPhone.' to confirm a specialist’s day. Routine outpatient hours: '.config('pearlie.hospital.hours_outpatient').'.',
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
                'answer' => 'The appointment deposit is KSh '.$depositAmount.'. For an appointment booked through Pearlie, an M-Pesa payment prompt is sent to your phone. Call '.$appointmentPhone.' for other payment questions.',
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
                'answer' => 'Hello! I’m Pearlie, your healthcare assistant at '.$hospitalName.'. How can I help you today?',
            ],
            [
                'category' => 'greeting',
                'subcategory' => 'swahili',
                'keywords' => ['habari', 'hujambo', 'sijambo', 'jambo', 'mambo', 'vipi', 'niaje', 'sasa', 'shikamoo', 'salama', 'poa'],
                'question' => 'Salamu kwa Kiswahili',
                'answer' => 'Habari! Mimi ni Pearlie, msaidizi wako wa afya katika '.$this->swahiliHospitalName().'. Naweza kukusaidia vipi leo?',
            ],
            [
                'category' => 'location',
                'subcategory' => 'swahili',
                'keywords' => ['mko wapi', 'mahali', 'anwani', 'wapi'],
                'question' => 'Hospitali iko wapi?',
                'answer' => $this->swahiliHospitalName().' iko '.str_replace('Nyahururu-Nyeri Road', 'barabara ya Nyahururu-Nyeri', $hospitalLocation).'.',
            ],
            [
                'category' => 'hours',
                'subcategory' => 'swahili',
                'keywords' => ['mnafanya kazi saa ngapi', 'saa za kazi', 'muda'],
                'question' => 'Hospitali inafunguliwa saa ngapi?',
                'answer' => 'Huduma za dharura na wagonjwa wa nje zinapatikana masaa 24/7. Kliniki za wataalamu zinafanyika wakati wa wiki — piga '.$appointmentPhone.' kuthibitisha.',
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
                'keywords' => ['dharura', 'haraka', 'ajali', 'msaada wa haraka', 'msada wa haraka'],
                'question' => 'Nifanye nini wakati wa dharura?',
                'answer' => 'Kwa dharura, piga '.$emergencyPhone.' mara moja. Tunapatikana masaa 24/7.',
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
                'answer' => $this->swahiliHospitalName().' inatoa huduma za wagonjwa wa nje (24/7), kulazwa, upasuaji, dialysis, saratani (oncology), IVF na uzazi, kliniki za wataalamu, physiotherapy, CT scan, X-ray, ultrasound, biopsy, ECG/ECHO/EEG, endoscopy, maabara, uzazi na afya ya watoto, family planning, uchunguzi wa afya, meno, macho, na dharura.',
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

    private function emergencyHours(): string
    {
        return (string) pearlie_config('hospital.hours_emergency', '24/7');
    }

    private function swahiliHospitalName(): string
    {
        return 'Hospitali ya '.(string) pearlie_config('hospital.name', 'Pearl Hospital');
    }
}
