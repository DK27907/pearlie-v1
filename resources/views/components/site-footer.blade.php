<footer class="bg-[#0a2f44] text-slate-200">
    @php
        $tenantHospital = hospital();
        $isPlatformPage = $tenantHospital === null
            || request()->routeIs('login', 'register', 'password.request', 'password.email', 'password.reset', 'password.store', 'superadmin.*');
        $isPlatformLogin = request()->routeIs('login');
        $branding = $isPlatformPage ? platform_branding() : hospital_branding();
        $tenantBranding = $tenantHospital ? tenant_branding($tenantHospital) : null;
        $assistantUrl = $tenantHospital ? route('tenant.chat.page', $tenantHospital->slug) : route('home');
        $botName = $tenantHospital?->chatbotName() ?? 'Assistant';
        $servicesUrl = $tenantHospital ? route('tenant.home', $tenantHospital->slug).'#services' : route('home').'#services';
        $whatsappNumber = preg_replace('/\D+/', '', (string) pearlie_config('hospital.whatsapp_number'));
        $supportEmail = config('mail.from.address');
    @endphp
    @if ($isPlatformPage)
        @if ($isPlatformLogin)
            <div class="border-t border-white/10 bg-[#082638]">
                <div class="mx-auto grid max-w-screen-2xl gap-5 px-4 py-6 text-sm text-slate-300 sm:px-6 md:grid-cols-3 lg:px-8">
                    <div>
                        <p class="font-bold text-white">{{ $branding['header_text'] }}</p>
                        <p class="mt-1 text-xs text-slate-400">© {{ date('Y') }} {{ $branding['header_text'] }}. All rights reserved.</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-xs">
                        <a class="hover:text-white" href="#">Privacy</a>
                        <a class="hover:text-white" href="#">Terms</a>
                        <p class="w-full text-slate-500">{{ $branding['footer_text'] }}</p>
                    </div>
                    @if ($supportEmail)
                        <div class="md:text-right">
                            <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Platform support</p>
                            <a class="mt-1 inline-block text-sm font-semibold text-white hover:text-cyan-200" href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>
                        </div>
                    @endif
                </div>
            </div>
        @else
            <div class="border-t border-white/10">
                <div class="mx-auto flex max-w-screen-2xl xl:max-w-[90rem] 2xl:max-w-[110rem] px-4 py-4 text-xs text-slate-400 sm:px-6 lg:px-8">
                    <p>{{ $branding['footer_text'] }}</p>
                </div>
            </div>
        @endif
    @else
    <div class="mx-auto grid max-w-screen-2xl xl:max-w-[90rem] 2xl:max-w-[110rem] gap-10 px-4 py-12 sm:px-6 md:grid-cols-3 lg:px-8">
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
                @if ($tenantBranding['hours'])
                    <p>Hours:
                        @if (is_array($tenantBranding['hours']))
                            {{ collect($tenantBranding['hours'])
                                ->filter(fn ($hours) => is_array($hours) && ! ($hours['closed'] ?? false) && filled($hours['open'] ?? null) && filled($hours['close'] ?? null))
                                ->map(fn ($hours, $day) => ucfirst($day).': '.$hours['open'].'-'.$hours['close'])
                                ->implode(' · ') }}
                        @else
                            {{ $tenantBranding['hours'] }}
                        @endif
                    </p>
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
                @if (pearlie_config('hospital.hours_emergency') || pearlie_config('hospital.emergency_phone'))
                    <li class="pt-2 font-semibold text-white">Emergency @if (pearlie_config('hospital.hours_emergency')) ({{ pearlie_config('hospital.hours_emergency') }}) @endif: @if (pearlie_config('hospital.emergency_phone')) <a href="tel:{{ pearlie_config('hospital.emergency_phone') }}" class="hover:text-cyan-200">{{ pearlie_config('hospital.emergency_phone') }}</a> @endif</li>
                @endif
            </ul>
        </section>
    </div>
    <div class="border-t border-white/10">
        <div class="mx-auto flex max-w-screen-2xl xl:max-w-[90rem] 2xl:max-w-[110rem] flex-col gap-2 px-4 py-4 text-xs text-slate-400 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
            <p>{{ $branding['footer_text'] }}</p>
            <p>Powered by AxiomForge Digital Solutions</p>
        </div>
    </div>
    @endif
</footer>
