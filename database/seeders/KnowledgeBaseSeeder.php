<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\KnowledgeBase;

class KnowledgeBaseSeeder extends Seeder
{
    public function run(): void
    {
        $entries = [

            // ──────────────────────────────────────────────
            // 1. LOCATION & CONTACT
            // ──────────────────────────────────────────────
            [
                'category' => 'location',
                'subcategory' => 'address',
                'keywords' => json_encode([
                    'where', 'location', 'address', 'find', 'directions', 'map',
                    'pearl hospital located', 'pearl hospital address', 'pearl hospital location',
                    'vin plaza', 'nyahururu-nyeri road', 'nyahururu town', 'laikipia county',
                ]),
                'question' => 'Where is Pearl Hospital located?',
                'answer' => 'Pearl Hospital is located at Vin Plaza, along Nyahururu-Nyeri Road, in Nyahururu Town, Laikipia County, Kenya. It is situated in the central business district, easily accessible by public and private transport. Google Maps directions are available upon request.',
                'source' => 'Pearl Hospital Administration',
                'last_updated' => now(),
            ],
            [
                'category' => 'location',
                'subcategory' => 'contact',
                'keywords' => json_encode([
                    'phone', 'phone number', 'contact', 'call', 'telephone', 'mobile', 'reach',
                    'pearl hospital phone', 'hospital contact', 'front desk', 'reception',
                    'appointment phone', 'emergency number', 'hotline',
                ]),
                'question' => 'How can I contact Pearl Hospital?',
                'answer' => 'You can reach Pearl Hospital through the following channels: Phone: 0700000000 (Main Line), Emergency Hotline: 0712345678, Email: info@pearlhospital.co.ke, WhatsApp: 0700000000. Our reception desk is available 24/7 at Vin Plaza, Nyahururu.',
                'source' => 'Pearl Hospital Administration',
                'last_updated' => now(),
            ],
            [
                'category' => 'location',
                'subcategory' => 'parking',
                'keywords' => json_encode([
                    'parking', 'car park', 'vehicle', 'drive', 'drop off',
                    'where to park', 'parking space', 'parking available',
                ]),
                'question' => 'Is parking available at Pearl Hospital?',
                'answer' => 'Yes, Pearl Hospital offers secure parking for patients, visitors, and staff. There is designated parking at Vin Plaza with 24/7 security surveillance. Drop-off areas are available at the main entrance for patients with mobility challenges.',
                'source' => 'Pearl Hospital Administration',
                'last_updated' => now(),
            ],

            // ──────────────────────────────────────────────
            // 2. SERVICES & DEPARTMENTS
            // ──────────────────────────────────────────────
            [
                'category' => 'services',
                'subcategory' => 'general',
                'keywords' => json_encode([
                    'services', 'departments', 'specialties',
                    'what services', 'what departments', 'hospital services',
                    'pearl hospital services', 'specialties offered',
                ]),
                'question' => 'What services does Pearl Hospital offer?',
                'answer' => 'Pearl Hospital is a multi-specialty healthcare facility offering a wide range of services including:
        • Emergency Medicine (24/7)
        • Oncology (Cancer Care)
        • Cardiology (Heart Care)
        • Radiology & Diagnostic Imaging (X-ray, Ultrasound, CT, MRI)
        • Outpatient & Inpatient Services
        • Maternity & Child Health
        • General Surgery
        • Orthopedics
        • Internal Medicine
        • Laboratory Services
        • Pharmacy Services
        • Physiotherapy & Rehabilitation
        • Mental Health & Wellness
        • Dental and ENT Services',
                'source' => 'Pearl Hospital Medical Director',
                'last_updated' => now(),
            ],
            [
                'category' => 'services',
                'subcategory' => 'emergency',
                'keywords' => json_encode([
                    'emergency', 'accident', 'urgent', 'casualty', 'immediate',
                    'emergency services', 'emergency care', 'emergency room',
                    'accident and emergency', 'trauma', 'critical care',
                ]),
                'question' => 'What emergency services does Pearl Hospital provide?',
                'answer' => 'Pearl Hospital offers 24/7 Emergency Services with a dedicated Emergency Department staffed by experienced doctors, nurses, and paramedics. We handle all types of emergencies including:
        • Trauma & Accidents
        • Cardiac Emergencies (Heart Attacks)
        • Stroke Management
        • Breathing Difficulties
        • Severe Allergic Reactions
        • Poisoning and Overdose
        • Pediatric Emergencies
        • Obstetric & Gynecological Emergencies
        • Psychiatric Emergencies
        Our emergency team is trained in Advanced Cardiac Life Support (ACLS) and Trauma Life Support (ATLS).',
                'source' => 'Pearl Hospital Emergency Department',
                'last_updated' => now(),
            ],
            [
                'category' => 'services',
                'subcategory' => 'radiology',
                'keywords' => json_encode([
                    'radiology', 'x-ray', 'ultrasound', 'scan', 'imaging',
                    'diagnostic imaging', 'radiology services', 'imaging services',
                    'ultrasound services',
                ]),
                'question' => 'What radiology and imaging services are available at Pearl Hospital?',
                'answer' => 'Pearl Hospital has a fully equipped Radiology Department offering:
        • Digital X-Ray
        • Ultrasound (2D, 3D, and Doppler)
        • CT Scan (Computed Tomography)
        • MRI (Magnetic Resonance Imaging)
        • Mammography
        • Fluoroscopy
        • Bone Densitometry
        All imaging services are performed by certified radiographers and interpreted by experienced radiologists.',
                'source' => 'Pearl Hospital Radiology Department',
                'last_updated' => now(),
            ],
            [
                'category' => 'services',
                'subcategory' => 'pharmacy',
                'keywords' => json_encode([
                    'pharmacy', 'medication', 'medicine', 'prescription', 'chemist',
                    'medicines', 'pharmaceutical', 'drug store',
                ]),
                'question' => 'Does Pearl Hospital have a pharmacy?',
                'answer' => 'Yes, Pearl Hospital has a fully stocked 24/7 Pharmacy offering:
        • Prescription Medications
        • Over-the-Counter Drugs
        • Chronic Disease Medications (Hypertension, Diabetes, Asthma)
        • Antibiotics and Pain Relievers
        • Vaccines and Immunizations
        • Nutritional Supplements
        Our pharmacists are available to counsel patients on proper medication use, side effects, and drug interactions.',
                'source' => 'Pearl Hospital Pharmacy Department',
                'last_updated' => now(),
            ],
            [
                'category' => 'services',
                'subcategory' => 'laboratory',
                'keywords' => json_encode([
                    'laboratory', 'lab', 'tests', 'pathology', 'blood', 'urine',
                    'lab services', 'laboratory services', 'pathology services',
                    'blood test', 'urine test', 'stool test', 'culture',
                ]),
                'question' => 'What laboratory services are available at Pearl Hospital?',
                'answer' => 'Pearl Hospital operates a modern, ISO-compliant laboratory offering:
        • Hematology (Blood Counts, Coagulation)
        • Clinical Chemistry (Liver, Kidney, Thyroid, Lipid Profiles)
        • Microbiology (Bacterial, Fungal, Parasitic Cultures)
        • Serology & Immunology (HIV, Hepatitis, Syphilis)
        • Urinalysis & Stool Analysis
        • Histopathology & Cytology
        • Blood Banking & Transfusion Services
        • PCR and Molecular Testing (Coming Soon)
        Results are available within 24–72 hours depending on the test.',
                'source' => 'Pearl Hospital Laboratory Department',
                'last_updated' => now(),
            ],

            // ──────────────────────────────────────────────
            // 3. APPOINTMENTS
            // ──────────────────────────────────────────────
            [
                'category' => 'appointments',
                'subcategory' => 'booking',
                'keywords' => json_encode([
                    'appointment', 'book', 'schedule', 'consultation',
                    'book appointment', 'schedule appointment', 'appointment booking',
                    'see a doctor', 'consult a doctor', 'visit doctor',
                ]),
                'question' => 'How do I book an appointment at Pearl Hospital?',
                'answer' => 'You can book an appointment at Pearl Hospital through the following ways:
        1. Call us at 0700000000 (Monday to Saturday, 8:00 AM – 6:00 PM)
        2. WhatsApp us at 0700000000
        3. Visit our reception desk at Vin Plaza (8:00 AM – 6:00 PM, Monday to Saturday)
        4. Email us at appointments@pearlhospital.co.ke
        5. Walk-in consultations are available (pre-booking recommended to reduce waiting time)
        For specialist consultations, please provide your full name, phone number, preferred date, and reason for visit.',
                'source' => 'Pearl Hospital Administration',
                'last_updated' => now(),
            ],
            [
                'category' => 'appointments',
                'subcategory' => 'cancellation',
                'keywords' => json_encode([
                    'cancel appointment', 'change appointment', 'reschedule',
                    'appointment cancellation', 'cancel booking',
                ]),
                'question' => 'What is the cancellation policy for appointments?',
                'answer' => 'To cancel or reschedule an appointment, please contact us at least 24 hours in advance. You can call 0700000000 or WhatsApp 0700000000. Repeated no-shows may result in a consultation fee being charged. We appreciate your cooperation to help us serve all patients efficiently.',
                'source' => 'Pearl Hospital Administration',
                'last_updated' => now(),
            ],

            // ──────────────────────────────────────────────
            // 4. HOURS OF OPERATION
            // ──────────────────────────────────────────────
            [
                'category' => 'hours',
                'subcategory' => 'outpatient',
                'keywords' => json_encode([
                    'hours', 'open', 'operating', 'working', 'schedule',
                    'operating hours', 'open hours', 'working hours',
                    'outpatient hours', 'consultation hours',
                ]),
                'question' => 'What are Pearl Hospital\'s operating hours?',
                'answer' => 'Pearl Hospital operates as follows:
        • Emergency Department: 24/7 (including all public holidays)
        • Outpatient Clinic: 8:00 AM – 6:00 PM, Monday to Saturday
        • Pharmacy: 24/7
        • Laboratory: 24/7
        • Radiology/Imaging: 8:00 AM – 6:00 PM, Monday to Saturday (emergency scans available 24/7)
        • Inpatient Wards: 24/7
        We are closed on Sundays for outpatient services, but emergencies are handled 24/7.',
                'source' => 'Pearl Hospital Administration',
                'last_updated' => now(),
            ],

            // ──────────────────────────────────────────────
            // 5. VISITING HOURS
            // ──────────────────────────────────────────────
            [
                'category' => 'visiting',
                'subcategory' => 'general',
                'keywords' => json_encode([
                    'visiting hours', 'visit patient', 'visitors', 'family',
                    'when can I visit', 'visiting time', 'hospital visiting',
                ]),
                'question' => 'What are the visiting hours at Pearl Hospital?',
                'answer' => 'Visiting hours at Pearl Hospital are:
        • General Wards: 3:00 PM – 6:00 PM daily
        • ICU (Intensive Care Unit): 4:00 PM – 5:00 PM daily (restricted to 1 visitor at a time)
        • Maternity Ward: 2:00 PM – 6:00 PM daily
        • Pediatric Ward: 2:00 PM – 6:00 PM daily (parents/guardians allowed 24/7)
        • Visiting may be restricted during outbreaks or emergencies.
        We recommend a maximum of 2 visitors per patient at a time to ensure patient comfort.',
                'source' => 'Pearl Hospital Administration',
                'last_updated' => now(),
            ],

            // ──────────────────────────────────────────────
            // 6. PAYMENT & INSURANCE
            // ──────────────────────────────────────────────
            [
                'category' => 'payment',
                'subcategory' => 'methods',
                'keywords' => json_encode([
                    'payment', 'pay', 'cost', 'fees', 'consultation fee',
                    'how much', 'price', 'charges', 'medical bill',
                ]),
                'question' => 'What payment methods does Pearl Hospital accept?',
                'answer' => 'Pearl Hospital accepts the following payment methods:
        • M-Pesa (Paybill: 123456, Account: Your Patient ID/Visit Number)
        • Cash (KES)
        • Bank Transfers
        • Visa and MasterCard (at the reception)
        • Medical Insurance (NHIF, AAR, CIC, Jubilee, and most major insurance providers)
        For insurance claims, please carry your insurance card and a valid ID. Consultation fees range from KES 1,500 to KES 5,000 depending on the specialist.',
                'source' => 'Pearl Hospital Finance Department',
                'last_updated' => now(),
            ],
            [
                'category' => 'payment',
                'subcategory' => 'insurance',
                'keywords' => json_encode([
                    'insurance', 'cover', 'NHIF', 'private insurance',
                    'accepted insurance', 'health insurance', 'AAR', 'CIC', 'Jubilee',
                ]),
                'question' => 'What insurance providers are accepted at Pearl Hospital?',
                'answer' => 'Pearl Hospital accepts a wide range of insurance providers including:
        • NHIF (National Hospital Insurance Fund)
        • AAR Insurance
        • CIC Insurance
        • Jubilee Insurance
        • APA Insurance
        • Pioneer Insurance
        • UAP Old Mutual
        • Britam
        • Madison Insurance
        • Corporate and Group Medical Schemes
        Please confirm coverage with your insurance provider before your visit. For direct billing, present your insurance card and valid ID at reception.',
                'source' => 'Pearl Hospital Finance Department',
                'last_updated' => now(),
            ],

            // ──────────────────────────────────────────────
            // 7. DOCTORS & SPECIALISTS
            // ──────────────────────────────────────────────
            [
                'category' => 'doctors',
                'subcategory' => 'general',
                'keywords' => json_encode([
                    'doctors', 'specialist', 'physician', 'surgeon', 'consultant',
                    'medical staff', 'doctor schedule', 'available doctors',
                ]),
                'question' => 'What specialists are available at Pearl Hospital?',
                'answer' => 'Pearl Hospital has a team of highly qualified specialists including:
        • General Surgeons
        • Gynecologists & Obstetricians
        • Pediatricians
        • Cardiologists
        • Oncologists
        • Orthopedic Surgeons
        • Neurologists
        • Radiologists
        • Pathologists
        • Internal Medicine Physicians
        • Emergency Medicine Specialists
        • Psychiatrists
        • ENT Specialists
        • Ophthalmologists
        • Dentists
        All our specialists are registered with the Kenya Medical Practitioners and Dentists Council (KMPDC).',
                'source' => 'Pearl Hospital Medical Director',
                'last_updated' => now(),
            ],

            // ──────────────────────────────────────────────
            // 8. AMENITIES
            // ──────────────────────────────────────────────
            [
                'category' => 'amenities',
                'subcategory' => 'food',
                'keywords' => json_encode([
                    'food', 'cafeteria', 'restaurant', 'eat', 'meal', 'canteen',
                    'where to eat', 'hospital food',
                ]),
                'question' => 'Is there a cafeteria at Pearl Hospital?',
                'answer' => 'Yes, Pearl Hospital has a cafeteria located on the ground floor. It offers:
        • Affordable meals for patients, visitors, and staff
        • Breakfast, lunch, and dinner options
        • Special dietary meals for patients (e.g., diabetic, soft diet)
        • Snacks and beverages
        Opening hours: 7:00 AM – 8:00 PM daily. Clean and comfortable seating area available.',
                'source' => 'Pearl Hospital Administration',
                'last_updated' => now(),
            ],

            // ──────────────────────────────────────────────
            // 9. PATIENT RESOURCES
            // ──────────────────────────────────────────────
            [
                'category' => 'resources',
                'subcategory' => 'admission',
                'keywords' => json_encode([
                    'admission', 'admitted', 'hospitalized', 'inpatient',
                    'admission process', 'what to bring', 'patient admission',
                ]),
                'question' => 'What is the admission process at Pearl Hospital?',
                'answer' => 'The admission process at Pearl Hospital is as follows:
        1. Registration at reception with your ID and insurance card
        2. Triage and assessment by a nurse
        3. Consultation with a doctor (General or Specialist)
        4. If needed, admission to the appropriate ward
        5. Informed consent and treatment plan discussion
        6. Billing and payment (cash, M-Pesa, or insurance)
        7. Admission to your room or bed
        Please bring your ID/Passport, insurance card, and any relevant medical records.
        We also offer emergency admission 24/7 for urgent cases.',
                'source' => 'Pearl Hospital Administration',
                'last_updated' => now(),
            ],
            [
                'category' => 'resources',
                'subcategory' => 'discharge',
                'keywords' => json_encode([
                    'discharge', 'leave hospital', 'going home',
                    'discharge process', 'hospital discharge',
                ]),
                'question' => 'What is the discharge process at Pearl Hospital?',
                'answer' => 'The discharge process includes:
        1. Doctor\'s approval for discharge
        2. Nursing handover with discharge instructions
        3. Medication reconciliation and prescriptions
        4. Final billing and payment settlement
        5. Follow-up appointment scheduling (if needed)
        6. Patient discharge and care instructions
        Please ask for a discharge summary and all relevant medical reports.',
                'source' => 'Pearl Hospital Administration',
                'last_updated' => now(),
            ],

            // ──────────────────────────────────────────────
            // 10. GREETINGS & GENERAL
            // ──────────────────────────────────────────────
            [
                'category' => 'greeting',
                'subcategory' => 'hello',
                'keywords' => json_encode([
                    'hello', 'hi', 'hey', 'greetings', 'good morning',
                    'good afternoon', 'good evening', 'how are you',
                ]),
                'question' => 'Greeting',
                'answer' => 'Hello! I\'m Pearlie, your healthcare assistant at Pearl Hospital. I\'m here to help you with information about our hospital, services, appointments, and more. How can I assist you today?',
                'source' => 'Pearl Hospital Administration',
                'last_updated' => now(),
            ],
            [
                'category' => 'general',
                'subcategory' => 'feedback',
                'keywords' => json_encode([
                    'feedback', 'complaint', 'suggestion', 'praise',
                    'report a problem', 'complain', 'suggest',
                ]),
                'question' => 'How do I provide feedback to Pearl Hospital?',
                'answer' => 'We value your feedback! You can provide feedback through:
        1. Suggestion boxes located at the reception, cafeteria, and ward entrances
        2. Email us at feedback@pearlhospital.co.ke
        3. Call our Customer Care desk at 0700000000
        4. WhatsApp us at 0700000000
        5. Speak to our Customer Care representative at reception
        Your feedback helps us improve our services and patient experience.',
                'source' => 'Pearl Hospital Administration',
                'last_updated' => now(),
            ],

        ];

        foreach ($entries as $entry) {
            KnowledgeBase::create($entry);
        }
    }
}