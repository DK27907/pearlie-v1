<footer class="bg-[#0a2f44] text-slate-200">
    @php
        $tenantHospital = hospital();
        $branding = hospital_branding();
        $assistantUrl = $tenantHospital ? route('tenant.chat.page', $tenantHospital->slug) : route('pearlie.index');
        $botName = $tenantHospital?->chatbotName() ?? 'Assistant';
        $servicesUrl = $tenantHospital ? route('tenant.home', $tenantHospital->slug).'#services' : route('home').'#services';
        $whatsappNumber = preg_replace('/\D+/', '', (string) pearlie_config('hospital.whatsapp_number'));
    @endphp
    <div class="mx-auto grid max-w-7xl gap-10 px-4 py-12 sm:px-6 md:grid-cols-3 lg:px-8">
        <section>
            <h2 class="text-lg font-bold text-white">{{ $branding['header_text'] }}</h2>
            <address class="mt-4 space-y-2 text-sm not-italic leading-6 text-slate-300">
                @if (pearlie_config('hospital.location'))
                    <p>{{ pearlie_config('hospital.location') }}</p>
                @endif
                @if (pearlie_config('hospital.appointment_phone'))
                    <p>Phone: <a class="hover:text-white" href="tel:{{ pearlie_config('hospital.appointment_phone') }}">{{ pearlie_config('hospital.appointment_phone') }}</a></p>
                @endif
                @if (pearlie_config('hospital.email'))
                    <p>Email: <a class="hover:text-white" href="mailto:{{ pearlie_config('hospital.email') }}">{{ pearlie_config('hospital.email') }}</a></p>
                @endif
                @if (pearlie_config('hospital.website'))
                    <p><a class="hover:text-white" href="{{ pearlie_config('hospital.website') }}">{{ pearlie_config('hospital.website') }}</a></p>
                @endif
            </address>
        </section>

        <section>
            <h2 class="text-sm font-bold uppercase tracking-wider text-white">Quick links</h2>
            <ul class="mt-4 space-y-2 text-sm text-slate-300">
                <li><a class="hover:text-white" href="{{ $assistantUrl }}">Chat with {{ $botName }}</a></li>
                <li><a class="hover:text-white" href="{{ $assistantUrl }}">Book Appointment</a></li>
                <li><a class="hover:text-white" href="{{ $servicesUrl }}">Our Services</a></li>
                @if ($whatsappNumber !== '')
                    <li><a class="hover:text-white" href="https://wa.me/{{ $whatsappNumber }}">WhatsApp</a></li>
                @endif
                @if (pearlie_config('hospital.email'))
                    <li><a class="hover:text-white" href="mailto:{{ pearlie_config('hospital.email') }}">Contact Us</a></li>
                @endif
            </ul>
        </section>

        <section>
            <h2 class="text-sm font-bold uppercase tracking-wider text-white">Patient information</h2>
            <ul class="mt-4 space-y-2 text-sm text-slate-300">
                <li><a class="hover:text-white" href="#">Privacy Policy</a></li>
                <li><a class="hover:text-white" href="#">Terms of Service</a></li>
                <li><a class="hover:text-white" href="#">Patient Rights</a></li>
                <li class="pt-2 font-semibold text-white">Emergency ({{ pearlie_config('hospital.hours_emergency') }}): <a href="tel:{{ pearlie_config('hospital.emergency_phone') }}" class="hover:text-cyan-200">{{ pearlie_config('hospital.emergency_phone') }}</a></li>
            </ul>
        </section>
    </div>
    <div class="border-t border-white/10">
        <div class="mx-auto flex max-w-7xl flex-col gap-2 px-4 py-4 text-xs text-slate-400 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
            <p>{{ $branding['footer_text'] }}</p>
            <p>Powered by AxiomForge Digital Solutions</p>
        </div>
    </div>
</footer>
