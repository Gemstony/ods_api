<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="ODS — Zero Distance Services. On-demand delivery infrastructure connecting distributors, riders and receivers across Tanzania.">

    <title>ODS — Zero Distance Services | On-Demand Delivery</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />

    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif

    <style>
        html { scroll-behavior: smooth; }
        .reveal { opacity: 0; transform: translateY(24px); transition: opacity .7s ease, transform .7s ease; }
        .reveal.is-visible { opacity: 1; transform: none; }
        @media (prefers-reduced-motion: reduce) {
            .reveal { opacity: 1; transform: none; transition: none; }
            html { scroll-behavior: auto; }
        }
    </style>
</head>
<body class="bg-white text-slate-800 antialiased">

{{-- ─────────────────────────── Navigation ─────────────────────────── --}}
<header class="sticky top-0 z-50 border-b border-slate-200 bg-white/90 backdrop-blur">
    <div class="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
        <a href="#top" class="flex items-center gap-2.5">
            <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-[#0F9D58] text-white">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 3 14h7l-1 8 10-12h-7l1-8z"/></svg>
            </span>
            <span class="text-lg font-bold tracking-tight text-slate-900">ODS</span>
            <span class="hidden text-xs font-medium uppercase tracking-widest text-slate-500 sm:inline">Zero Distance Services</span>
        </a>

        <nav class="hidden items-center gap-8 text-sm font-medium text-slate-600 md:flex">
            <a href="#services" class="transition hover:text-[#0F9D58]">Services</a>
            <a href="#how-it-works" class="transition hover:text-[#0F9D58]">How it works</a>
            <a href="#ecosystem" class="transition hover:text-[#0F9D58]">Ecosystem</a>
            <a href="#trust" class="transition hover:text-[#0F9D58]">Trust &amp; security</a>
        </nav>

        <div class="flex items-center gap-3">
            <a href="#get-started" class="hidden rounded-lg bg-[#0F9D58] px-4 py-2 text-sm font-semibold text-white transition hover:bg-[#0B7A43] sm:inline-block">
                Get the app
            </a>
            <button id="nav-toggle" class="rounded-lg border border-slate-200 p-2 text-slate-600 md:hidden" aria-label="Toggle menu" aria-expanded="false">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
        </div>
    </div>
    <div id="mobile-nav" class="hidden border-t border-slate-200 bg-white px-4 py-3 md:hidden">
        <nav class="flex flex-col gap-1 text-sm font-medium text-slate-600">
            <a href="#services" class="rounded-lg px-3 py-2 hover:bg-slate-50">Services</a>
            <a href="#how-it-works" class="rounded-lg px-3 py-2 hover:bg-slate-50">How it works</a>
            <a href="#ecosystem" class="rounded-lg px-3 py-2 hover:bg-slate-50">Ecosystem</a>
            <a href="#trust" class="rounded-lg px-3 py-2 hover:bg-slate-50">Trust &amp; security</a>
            <a href="#get-started" class="mt-1 rounded-lg bg-[#0F9D58] px-3 py-2 text-center font-semibold text-white">Get the app</a>
        </nav>
    </div>
</header>

<main id="top">

    {{-- ─────────────────────────── Hero ─────────────────────────── --}}
    <section class="relative overflow-hidden bg-[#0C1F16] text-white">
        <div class="pointer-events-none absolute inset-0 opacity-[0.07]" style="background-image: radial-gradient(circle at 1px 1px, #fff 1px, transparent 0); background-size: 32px 32px;"></div>
        <div class="pointer-events-none absolute -right-40 -top-40 h-96 w-96 rounded-full bg-[#0F9D58]/30 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-40 left-1/3 h-80 w-80 rounded-full bg-[#22C55E]/20 blur-3xl"></div>

        <div class="relative mx-auto grid max-w-7xl gap-12 px-4 py-20 sm:px-6 lg:grid-cols-2 lg:items-center lg:px-8 lg:py-28">
            <div class="reveal">
                <p class="mb-4 inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-4 py-1.5 text-xs font-semibold uppercase tracking-widest text-[#7BE3A8]">
                    <span class="h-1.5 w-1.5 rounded-full bg-[#22C55E]"></span>
                    On-demand delivery infrastructure
                </p>
                <h1 class="text-4xl font-bold leading-tight tracking-tight sm:text-5xl lg:text-6xl">
                    Deliver anything.<br>
                    <span class="text-[#4ADE80]">Anywhere in Tanzania.</span>
                </h1>
                <p class="mt-6 max-w-xl text-lg leading-relaxed text-slate-300">
                    ODS connects distributors, riders and receivers on one secure platform —
                    priced transparently, tracked in real time, and verified at every handover.
                </p>
                <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                    <a href="#get-started" class="rounded-lg bg-[#0F9D58] px-6 py-3 text-center text-sm font-semibold text-white transition hover:bg-[#0B7A43]">
                        Request access
                    </a>
                    <a href="#how-it-works" class="rounded-lg border border-white/20 px-6 py-3 text-center text-sm font-semibold text-white transition hover:bg-white/10">
                        See how it works
                    </a>
                </div>

                <dl class="mt-12 grid grid-cols-3 gap-6 border-t border-white/10 pt-8">
                    <div>
                        <dt class="text-3xl font-bold text-white">24/7</dt>
                        <dd class="mt-1 text-xs font-medium uppercase tracking-wider text-slate-400">Dispatch coverage</dd>
                    </div>
                    <div>
                        <dt class="text-3xl font-bold text-white">4</dt>
                        <dd class="mt-1 text-xs font-medium uppercase tracking-wider text-slate-400">Connected roles</dd>
                    </div>
                    <div>
                        <dt class="text-3xl font-bold text-white">100%</dt>
                        <dd class="mt-1 text-xs font-medium uppercase tracking-wider text-slate-400">Verified handovers</dd>
                    </div>
                </dl>
            </div>

            {{-- Delivery status card mock --}}
            <div class="reveal hidden lg:block">
                <div class="rounded-2xl border border-white/10 bg-white/5 p-6 shadow-2xl backdrop-blur">
                    <div class="mb-5 flex items-center justify-between">
                        <p class="text-sm font-semibold text-white">Live delivery</p>
                        <span class="rounded-full bg-[#22C55E]/15 px-3 py-1 text-xs font-semibold text-[#4ADE80]">In transit</span>
                    </div>
                    <div class="space-y-4">
                        @foreach ([
                            ['Package collected', 'Branch · Kariakoo', 'Done', 'bg-[#22C55E]'],
                            ['Rider assigned', 'Nearest available agent', 'Done', 'bg-[#22C55E]'],
                            ['Out for delivery', 'Estimated 12 min', 'Active', 'bg-[#F59E0B]'],
                            ['Receiver verification', 'OTP at handover', 'Pending', 'bg-slate-500'],
                        ] as $step)
                        <div class="flex items-center gap-4">
                            <span class="h-2.5 w-2.5 shrink-0 rounded-full {{ $step[3] }}"></span>
                            <div class="flex-1">
                                <p class="text-sm font-medium text-white">{{ $step[0] }}</p>
                                <p class="text-xs text-slate-400">{{ $step[1] }}</p>
                            </div>
                            <span class="text-xs font-semibold {{ $step[2] === 'Active' ? 'text-[#FBBF24]' : ($step[2] === 'Pending' ? 'text-slate-400' : 'text-[#4ADE80]') }}">{{ $step[2] }}</span>
                        </div>
                        @endforeach
                    </div>
                    <div class="mt-6 flex items-center justify-between rounded-xl bg-white/5 px-4 py-3">
                        <span class="text-xs text-slate-400">Delivery fee</span>
                        <span class="text-sm font-bold text-white">TZS 4,500</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ─────────────────────────── Services ─────────────────────────── --}}
    <section id="services" class="bg-slate-50 py-20 lg:py-28">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="reveal mx-auto max-w-2xl text-center">
                <p class="text-sm font-semibold uppercase tracking-widest text-[#0F9D58]">What we do</p>
                <h2 class="mt-3 text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">Delivery infrastructure, end to end</h2>
                <p class="mt-4 text-lg text-slate-600">From the moment a package is priced to the moment a receiver signs for it — one platform, full visibility.</p>
            </div>

            <div class="mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    ['Instant pricing', 'Transparent fare calculation before you send — distance, weight and service tier priced upfront.',
                     'M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z'],
                    ['Smart dispatch', 'Deliveries are batched and assigned to the nearest available rider automatically — no idle agents, no delays.',
                     'M13 10V3L4 14h7v7l9-11h-7z'],
                    ['Verified handover', 'One-time verification codes confirm every pickup and drop-off. Disputes are logged and resolved with a full audit trail.',
                     'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z'],
                    ['Secure payments', 'Mobile money and manual payments reconciled against a double-entry ledger. Every shilling accounted for.',
                     'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z'],
                ] as $card)
                <div class="reveal rounded-xl border border-slate-200 bg-white p-6 transition hover:border-[#0F9D58]/40 hover:shadow-lg">
                    <span class="flex h-11 w-11 items-center justify-center rounded-lg bg-[#0F9D58]/10 text-[#0F9D58]">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $card[2] }}"/></svg>
                    </span>
                    <h3 class="mt-4 text-lg font-semibold text-slate-900">{{ $card[0] }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ $card[1] }}</p>
                </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ─────────────────────────── How it works ─────────────────────────── --}}
    <section id="how-it-works" class="py-20 lg:py-28">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="reveal mx-auto max-w-2xl text-center">
                <p class="text-sm font-semibold uppercase tracking-widest text-[#0F9D58]">Process</p>
                <h2 class="mt-3 text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">Four steps. Zero ambiguity.</h2>
            </div>

            <div class="mt-14 grid gap-10 md:grid-cols-4">
                @foreach ([
                    ['01', 'Create & price', 'A distributor or sender creates the delivery in the app and sees the exact price before committing.'],
                    ['02', 'Pay securely', 'Payment is made via mobile money or recorded as a manual payment pending approval.'],
                    ['03', 'We dispatch', 'Deliveries are batched by branch and assigned to the nearest available rider.'],
                    ['04', 'Verify & release', 'The receiver confirms the package with a one-time code. Funds settle through the ledger.'],
                ] as $i => $step)
                <div class="reveal relative">
                    @if ($i < 3)
                        <div class="absolute left-10 top-12 hidden h-px w-[calc(100%-2.5rem)] bg-slate-200 md:block"></div>
                    @endif
                    <div class="flex items-center gap-4">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#0F9D58] text-sm font-bold text-white">{{ $step[0] }}</span>
                        <h3 class="text-base font-semibold text-slate-900">{{ $step[1] }}</h3>
                    </div>
                    <p class="mt-3 pl-14 text-sm leading-relaxed text-slate-600">{{ $step[2] }}</p>
                </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ─────────────────────────── Ecosystem ─────────────────────────── --}}
    <section id="ecosystem" class="bg-[#F7FBF9] py-20 lg:py-28">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="reveal mx-auto max-w-2xl text-center">
                <p class="text-sm font-semibold uppercase tracking-widest text-[#0F9D58]">Ecosystem</p>
                <h2 class="mt-3 text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">Built for every participant</h2>
                <p class="mt-4 text-lg text-slate-600">Role-based access means everyone sees exactly what they need — and nothing they shouldn't.</p>
            </div>

            <div class="mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    ['Distributor', 'Send packages, track deliveries, manage branches and grow a rewards balance.', 'Store'],
                    ['Branch Manager', 'Oversee staff, batching and assignments for their branch from one dashboard.', 'Users'],
                    ['Rider', 'Accept jobs, navigate pickups, record earnings and manage availability.', 'Truck'],
                    ['Receiver', 'Track inbound packages and confirm deliveries with verification codes.', 'PackageCheck'],
                ] as $role)
                <div class="reveal rounded-xl border border-[#D6E7DE] bg-white p-6">
                    <span class="flex h-11 w-11 items-center justify-center rounded-lg bg-[#0F9D58] text-white">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            @if ($role[2] === 'Store')
                                <path d="M3 9l1-5h16l1 5M3 9v11h18V9M3 9h18M9 20v-6h6v6"/>
                            @elseif ($role[2] === 'Users')
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75M13 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0z"/>
                            @elseif ($role[2] === 'Truck')
                                <path d="M1 3h15v13H1zM16 8h4l3 3v5h-7V8zM5.5 21a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5zm11 0a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z"/>
                            @else
                                <path d="M16.5 9.4l-9-5.19M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16zM3.27 6.96L12 12.01l8.73-5.05M12 22.08V12"/>
                            @endif
                        </svg>
                    </span>
                    <h3 class="mt-4 text-lg font-semibold text-slate-900">{{ $role[0] }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ $role[1] }}</p>
                </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ─────────────────────────── Trust ─────────────────────────── --}}
    <section id="trust" class="py-20 lg:py-28">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="grid items-center gap-12 lg:grid-cols-2">
                <div class="reveal">
                    <p class="text-sm font-semibold uppercase tracking-widest text-[#0F9D58]">Trust &amp; security</p>
                    <h2 class="mt-3 text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">Accountability built in</h2>
                    <p class="mt-4 text-lg text-slate-600">Every handover, payment and dispute is recorded. ODS is designed so that trust is enforced by the system — not by hope.</p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            'One-time verification codes on every pickup and delivery',
                            'Immutable audit logs for all account and delivery actions',
                            'Role-based access control across five user roles',
                            'Encrypted tokens and hashed refresh-token rotation',
                            'Reconciliation-ready payment ledger with dispute resolution',
                        ] as $point)
                        <li class="flex items-start gap-3">
                            <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-[#0F9D58]/10 text-[#0F9D58]">
                                <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                            </span>
                            <span class="text-sm leading-relaxed text-slate-700">{{ $point }}</span>
                        </li>
                        @endforeach
                    </ul>
                </div>

                <div class="reveal">
                    <div class="rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
                        <h3 class="text-lg font-semibold text-slate-900">Why teams choose ODS</h3>
                        <dl class="mt-6 space-y-6">
                            @foreach ([
                                ['Predictable pricing', 'Know the cost before you commit — no surprise fees at handover.'],
                                ['Real-time visibility', 'Every party sees the same delivery status, updated live.'],
                                ['Fair rider earnings', 'Transparent per-delivery compensation with bonus tracking.'],
                                ['Dispute resolution', 'Structured dispute flow with evidence and owner-level review.'],
                            ] as $item)
                            <div>
                                <dt class="text-sm font-semibold text-slate-900">{{ $item[0] }}</dt>
                                <dd class="mt-1 text-sm text-slate-600">{{ $item[1] }}</dd>
                            </div>
                            @endforeach
                        </dl>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ─────────────────────────── CTA ─────────────────────────── --}}
    <section id="get-started" class="bg-[#0C1F16] py-20 text-white lg:py-24">
        <div class="mx-auto max-w-4xl px-4 text-center sm:px-6 lg:px-8">
            <h2 class="reveal text-3xl font-bold tracking-tight sm:text-4xl">Ready to move with zero distance?</h2>
            <p class="reveal mx-auto mt-4 max-w-2xl text-lg text-slate-300">
                Download the ODS app or contact our team to onboard your branch, fleet or delivery operation.
            </p>
            <div class="reveal mt-8 flex flex-col justify-center gap-3 sm:flex-row">
                <a href="mailto:hello@ods.example.com" class="rounded-lg bg-[#0F9D58] px-8 py-3 text-sm font-semibold text-white transition hover:bg-[#0B7A43]">Contact sales</a>
                <a href="#ecosystem" class="rounded-lg border border-white/20 px-8 py-3 text-sm font-semibold text-white transition hover:bg-white/10">Explore roles</a>
            </div>
        </div>
    </section>

</main>

{{-- ─────────────────────────── Footer ─────────────────────────── --}}
<footer class="border-t border-slate-200 bg-white py-12">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex flex-col items-center justify-between gap-6 md:flex-row">
            <div class="flex items-center gap-2.5">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-[#0F9D58] text-white">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 3 14h7l-1 8 10-12h-7l1-8z"/></svg>
                </span>
                <div>
                    <p class="text-sm font-bold text-slate-900">ODS — Zero Distance Services</p>
                    <p class="text-xs text-slate-500">On-demand delivery infrastructure · Tanzania</p>
                </div>
            </div>
            <nav class="flex flex-wrap justify-center gap-6 text-sm text-slate-600">
                <a href="#services" class="hover:text-[#0F9D58]">Services</a>
                <a href="#how-it-works" class="hover:text-[#0F9D58]">How it works</a>
                <a href="#ecosystem" class="hover:text-[#0F9D58]">Ecosystem</a>
                <a href="#trust" class="hover:text-[#0F9D58]">Trust</a>
            </nav>
        </div>
        <p class="mt-8 text-center text-xs text-slate-400">&copy; {{ date('Y') }} ODS. All rights reserved.</p>
    </div>
</footer>

<script>
    // Mobile nav toggle
    const toggle = document.getElementById('nav-toggle');
    const mobileNav = document.getElementById('mobile-nav');
    toggle?.addEventListener('click', () => {
        const open = mobileNav.classList.toggle('hidden') === false;
        toggle.setAttribute('aria-expanded', String(open));
    });
    mobileNav?.querySelectorAll('a').forEach(a =>
        a.addEventListener('click', () => mobileNav.classList.add('hidden'))
    );

    // Scroll reveal
    const io = new IntersectionObserver((entries) => {
        entries.forEach(e => {
            if (e.isIntersecting) {
                e.target.classList.add('is-visible');
                io.unobserve(e.target);
            }
        });
    }, { threshold: 0.12 });
    document.querySelectorAll('.reveal').forEach(el => io.observe(el));
</script>

</body>
</html>
