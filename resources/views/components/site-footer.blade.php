<footer class="bg-[#0a2f44] text-slate-200">
    @php
        $assistantUrl = request()->routeIs('tenant.home') && hospital()
            ? route('tenant.home', hospital()->slug)
            : url('/pearlie');
    @endphp
    <div class="mx-auto grid max-w-7xl gap-10 px-4 py-12 sm:px-6 md:grid-cols-3 lg:px-8">
        <section>
            <h2 class="text-lg font-bold text-white">{{ pearlie_config('hospital.name') }}</h2>
            <address class="mt-4 space-y-2 text-sm not-italic leading-6 text-slate-300">
                <p>{{ pearlie_config('hospital.location') }}</p>
                <p>Phone: <a class="hover:text-white" href="tel:{{ pearlie_config('hospital.appointment_phone') }}">{{ pearlie_config('hospital.appointment_phone') }}</a></p>
                <p>Email: <a class="hover:text-white" href="mailto:{{ pearlie_config('hospital.email') }}">{{ pearlie_config('hospital.email') }}</a></p>
                <p><a class="hover:text-white" href="{{ pearlie_config('hospital.website') }}">{{ pearlie_config('hospital.website') }}</a></p>
            </address>
        </section>

        <section>
            <h2 class="text-sm font-bold uppercase tracking-wider text-white">Quick links</h2>
            <ul class="mt-4 space-y-2 text-sm text-slate-300">
                <li><a class="hover:text-white" href="{{ $assistantUrl }}">Chat with Pearlie</a></li>
                <li><a class="hover:text-white" href="{{ $assistantUrl }}">Book Appointment</a></li>
                <li><a class="hover:text-white" href="{{ url('/#services') }}">Our Services</a></li>
                <li><a class="hover:text-white" href="https://wa.me/{{ pearlie_config('hospital.whatsapp_number') }}">WhatsApp</a></li>
                <li><a class="hover:text-white" href="mailto:{{ pearlie_config('hospital.email') }}">Contact Us</a></li>
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
            <p>© {{ date('Y') }} {{ pearlie_config('hospital.name') }}. All rights reserved.</p>
            <p>Powered by AxiomForge Digital Solutions</p>
        </div>
    </div>
</footer>
