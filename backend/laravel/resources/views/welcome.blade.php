<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#10152d">
    <meta name="description" content="Nexora Group builds technology ventures that connect people, empower businesses and open new possibilities.">
    <title>Nexora Group — Make What’s Next Matter</title>
    <style>
        :root {
            --night: #10152d;
            --night-2: #171e3b;
            --paper: #f5f2e9;
            --white: #fffdf7;
            --cyan: #25d9ff;
            --coral: #ff718f;
            --blue: #527bff;
            --ink: #151a32;
            --muted: #b7bdd0;
            --line: rgba(255,255,255,.16);
            --serif: Georgia, "Times New Roman", serif;
            --sans: "Avenir Next", Avenir, "Segoe UI", sans-serif;
            --max: 1240px;
        }

        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            margin: 0;
            background: var(--night);
            color: var(--white);
            font-family: var(--sans);
            line-height: 1.55;
            -webkit-font-smoothing: antialiased;
        }
        a { color: inherit; text-decoration: none; }
        button { font: inherit; }
        ::selection { background: var(--cyan); color: var(--night); }
        .wrap { width: min(var(--max), calc(100% - 64px)); margin-inline: auto; }

        .topline {
            min-height: 34px;
            padding: 7px 0;
            background: var(--cyan);
            color: var(--night);
            font-size: 10px;
            font-weight: 900;
            letter-spacing: .19em;
            text-transform: uppercase;
        }
        .topline .wrap { display:flex; justify-content:space-between; gap:15px; }

        .site-header {
            position: relative;
            z-index: 20;
            background: var(--night);
            border-bottom: 1px solid var(--line);
        }
        .nav {
            min-height: 88px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:28px;
        }
        .brand { display:flex; align-items:center; gap:12px; min-width:max-content; }
        .brand-mark {
            width:43px; height:43px; display:grid; place-items:center;
            color:var(--night); background:var(--cyan);
            transform:rotate(-7deg); border-radius:4px 14px 4px 14px;
            box-shadow:5px 5px 0 rgba(82,123,255,.55);
        }
        .brand-mark svg { width:31px; height:31px; transform:rotate(7deg); }
        .venture-brand { display:flex; align-items:center; gap:13px; }
        .venture-logo { width:43px; height:43px; flex:0 0 43px; display:grid; place-items:center; border-radius:12px; background:#172044; color:var(--cyan); }
        .venture-logo svg { width:29px; height:29px; }
        .venture:nth-child(2) .venture-logo { background:#202b61; color:#8fa8ff; border-radius:50%; }
        .venture:nth-child(3) .venture-logo { background:#25345e; color:#ff91a7; transform:rotate(45deg); }
        .venture:nth-child(3) .venture-logo svg { transform:rotate(-45deg); }
        .venture-name { line-height:1.05; }
        .brand-name { font-size:15px; font-weight:950; letter-spacing:.23em; }
        .brand-name small {
            display:block; margin-top:1px; color:#a8aec3;
            font-size:8px; font-weight:700; letter-spacing:.24em;
        }
        .nav-links { display:flex; align-items:center; gap:clamp(18px,3vw,38px); }
        .nav-links a {
            color:#c9cddd; font-size:12px; font-weight:700;
            transition:color .2s ease;
        }
        .nav-links a:hover { color:var(--cyan); }
        .nav-contact {
            display:inline-flex; align-items:center; gap:12px;
            padding:12px 16px; color:var(--night); background:var(--cyan);
            font-size:11px; font-weight:900; letter-spacing:.04em;
            border-radius:2px; transition:transform .2s ease, background .2s ease;
        }
        .nav-contact:hover { transform:translateY(-3px); background:#e5ff8b; }
        .menu-toggle {
            display:none; border:1px solid var(--line); background:transparent;
            color:var(--white); padding:9px 12px; border-radius:3px;
        }

        .hero {
            position:relative; overflow:hidden;
            padding:clamp(80px,10vw,145px) 0 70px;
            background:
                radial-gradient(ellipse at 83% 43%, rgba(99,124,255,.28), transparent 35%),
                radial-gradient(ellipse at 10% 100%, rgba(255,113,91,.13), transparent 35%),
                var(--night);
        }
        .hero-grid {
            position:absolute; inset:0; opacity:.16; pointer-events:none;
            background-image:linear-gradient(rgba(255,255,255,.09) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.09) 1px,transparent 1px);
            background-size:68px 68px;
            mask-image:linear-gradient(to right, transparent, black 45%, black);
        }
        .hero-inner { position:relative; display:grid; grid-template-columns:minmax(0,1.3fr) minmax(260px,.7fr); align-items:center; gap:35px; }
        .kicker {
            display:flex; align-items:center; gap:12px;
            color:var(--cyan); font-size:10px; font-weight:900;
            letter-spacing:.22em; text-transform:uppercase;
        }
        .kicker:before { content:""; width:28px; height:2px; background:var(--coral); }
        .hero h1 {
            max-width:850px; margin:25px 0 25px;
            font-size:clamp(54px,8.3vw,112px);
            line-height:.91; letter-spacing:-.075em; font-weight:900;
        }
        .hero h1 .outline {
            color:transparent; -webkit-text-stroke:1.5px var(--cyan);
            font-family:var(--serif); font-style:italic; font-weight:400;
            letter-spacing:-.065em;
        }
        .hero-copy { max-width:560px; color:#c0c6d8; font-size:16px; line-height:1.85; }
        .hero-actions { display:flex; align-items:center; gap:22px; flex-wrap:wrap; margin-top:32px; }
        .button-primary {
            display:inline-flex; align-items:center; gap:22px;
            padding:16px 19px; background:var(--cyan); color:var(--night);
            font-size:11px; font-weight:950; letter-spacing:.06em;
            transition:transform .2s ease, box-shadow .2s ease;
        }
        .button-primary:hover { transform:translateY(-4px); box-shadow:6px 6px 0 var(--coral); }
        .text-link { color:var(--white); font-size:12px; font-weight:800; border-bottom:1px solid var(--coral); padding-bottom:5px; }
        .hero-art {
            min-height:420px; position:relative; display:grid; place-items:center;
        }
        .orbit {
            width:min(33vw,390px); aspect-ratio:1; border:1px solid rgba(37,217,255,.5);
            border-radius:50%; position:relative; display:grid; place-items:center;
            box-shadow:0 0 0 34px rgba(37,217,255,.035), 0 0 0 68px rgba(99,124,255,.045);
        }
        .orbit:before,.orbit:after { content:""; position:absolute; border:1px solid rgba(99,124,255,.55); border-radius:50%; inset:16%; transform:rotate(48deg) scaleX(.52); }
        .orbit:after { transform:rotate(-48deg) scaleX(.52); border-color:rgba(255,113,91,.7); }
        .orbit-core {
            width:42%; aspect-ratio:1; display:grid; place-items:center;
            background:var(--coral); color:var(--night);
            font-size:clamp(42px,6vw,72px); font-weight:1000;
            border-radius:48% 52% 44% 56% / 52% 43% 57% 48%;
            transform:rotate(-12deg); box-shadow:16px 18px 0 rgba(37,217,255,.9);
        }
        .orbit-label {
            position:absolute; padding:9px 12px; background:var(--paper); color:var(--night);
            font-size:9px; font-weight:950; letter-spacing:.13em; text-transform:uppercase;
        }
        .orbit-label.one { top:9%; right:0; transform:rotate(5deg); }
        .orbit-label.two { bottom:12%; left:-5%; transform:rotate(-6deg); }
        .hero-foot {
            position:relative; margin-top:65px; padding-top:18px;
            border-top:1px solid var(--line); display:flex; justify-content:space-between;
            gap:20px; color:#929bb5; font-size:9px; font-weight:800; letter-spacing:.18em;
            text-transform:uppercase;
        }

        .ticker {
            overflow:hidden; background:var(--coral); color:var(--night);
            padding:15px 0; white-space:nowrap;
        }
        .ticker-track { display:flex; width:max-content; gap:28px; animation:marquee 28s linear infinite; }
        .ticker span { font-size:12px; font-weight:950; letter-spacing:.17em; text-transform:uppercase; }
        .ticker b { font-size:17px; }
        @keyframes marquee { to { transform:translateX(-50%); } }

        .section { padding:clamp(78px,9vw,125px) 0; }
        .section-paper { background:var(--paper); color:var(--ink); }
        .section-label {
            color:#5e6bd0; font-size:10px; font-weight:950;
            letter-spacing:.2em; text-transform:uppercase;
        }
        .section-title {
            max-width:780px; margin:17px 0 0;
            font-size:clamp(38px,5.7vw,72px); line-height:.99;
            letter-spacing:-.065em; font-weight:900;
        }
        .section-title em { font-family:var(--serif); font-weight:400; }
        .intro-grid { display:grid; grid-template-columns:1fr .72fr; gap:9%; align-items:end; }
        .intro-copy { color:#53596e; font-size:16px; line-height:1.9; max-width:480px; }
        .intro-copy strong { color:var(--ink); }

        .values {
            margin-top:65px; border-top:1px solid #d8d5cb;
            display:grid; grid-template-columns:repeat(4,1fr);
        }
        .value { padding:24px 20px 0 0; border-right:1px solid #d8d5cb; margin-right:20px; }
        .value:last-child { border:0; margin:0; }
        .value-num { color:#6a73d6; font-size:10px; font-weight:900; letter-spacing:.15em; }
        .value h3 { margin:13px 0 7px; font-size:17px; letter-spacing:-.03em; }
        .value p { margin:0; color:#62677a; font-size:12px; line-height:1.75; }

        .ventures-head { display:flex; justify-content:space-between; align-items:end; gap:30px; margin-bottom:48px; }
        .ventures-head .section-title { max-width:650px; }
        .ventures-note { max-width:300px; color:#b9bfd1; font-size:13px; line-height:1.8; }
        .venture-list { border-top:1px solid var(--line); }
        .venture {
            display:grid; grid-template-columns:72px minmax(190px,.8fr) 1.2fr 42px;
            gap:25px; align-items:center; padding:29px 10px;
            border-bottom:1px solid var(--line); transition:background .25s ease, padding .25s ease;
        }
        .venture:hover { background:rgba(99,124,255,.09); padding-inline:20px; }
        .venture-index { color:var(--cyan); font-size:11px; font-weight:900; letter-spacing:.12em; }
        .venture-name { font-size:clamp(26px,3.5vw,42px); font-weight:950; letter-spacing:-.06em; }
        .venture-type { display:block; color:#9ca5c0; font-size:9px; font-weight:850; letter-spacing:.16em; text-transform:uppercase; }
        .venture p { max-width:490px; margin:0; color:#c0c6d8; font-size:13px; line-height:1.8; }
        .venture-arrow {
            width:38px; height:38px; display:grid; place-items:center;
            color:var(--night); background:var(--cyan); border-radius:50%;
            font-size:19px; transition:transform .2s ease;
        }
        .venture:hover .venture-arrow { transform:rotate(45deg); }
        .venture:nth-child(2) .venture-arrow { background:var(--coral); }
        .venture:nth-child(3) .venture-arrow { background:#8c9bff; }

        .ai-feature { background:#dfe5ff; color:var(--night); overflow:hidden; }
        .ai-layout { display:grid; grid-template-columns:1fr .8fr; gap:7%; align-items:center; }
        .ai-feature .section-label { color:#515fc6; }
        .ai-feature .section-title { max-width:620px; }
        .ai-copy { color:#515873; font-size:14px; line-height:1.9; max-width:560px; }
        .ai-stamp {
            min-height:330px; position:relative; display:grid; place-items:center;
            background:var(--blue); overflow:hidden;
            clip-path:polygon(9% 0,100% 0,100% 83%,87% 100%,0 100%,0 13%);
        }
        .ai-stamp:before,.ai-stamp:after {
            content:""; position:absolute; width:250px; height:250px;
            border:1px solid rgba(255,255,255,.35); border-radius:50%;
        }
        .ai-stamp:after { width:180px; height:180px; }
        .ai-glyph { position:relative; z-index:1; color:var(--cyan); font-size:120px; font-weight:300; line-height:1; }
        .ai-stamp small {
            position:absolute; bottom:22px; right:24px; color:white;
            font-size:9px; font-weight:900; letter-spacing:.2em;
        }

        .future { background:var(--night-2); }
        .future-layout { display:grid; grid-template-columns:.8fr 1.2fr; gap:10%; }
        .future-copy { color:#b7bdd0; font-size:15px; line-height:1.9; }
        .future-copy p { margin-top:0; }
        .future-quote {
            padding:25px 0 0 28px; border-left:3px solid var(--coral);
            font-family:var(--serif); font-size:clamp(24px,3.4vw,39px);
            line-height:1.25; color:var(--white);
        }

        .contact {
            position:relative; overflow:hidden; padding:clamp(75px,9vw,120px) 0;
            background:var(--cyan); color:var(--night);
        }
        .contact:after {
            content:"N"; position:absolute; right:-25px; bottom:-180px;
            color:rgba(16,21,45,.07); font-size:480px; font-weight:1000;
            line-height:1; pointer-events:none;
        }
        .contact-inner { position:relative; z-index:1; display:flex; align-items:end; justify-content:space-between; gap:35px; }
        .contact .section-label { color:#515fc6; }
        .contact .section-title { max-width:760px; }
        .contact-copy { max-width:360px; color:#424a30; font-size:13px; line-height:1.8; }
        .contact-button {
            display:inline-flex; align-items:center; gap:22px; margin-top:18px;
            padding:16px 20px; background:var(--night); color:white;
            font-size:11px; font-weight:900; letter-spacing:.06em;
        }
        .contact-button:hover { background:#303a70; }

        .footer { background:#0b1025; padding:35px 0 25px; }
        .footer-top { display:flex; justify-content:space-between; align-items:start; gap:35px; padding-bottom:34px; }
        .footer-tag { max-width:300px; color:#939bb3; font-family:var(--serif); font-size:19px; font-style:italic; }
        .footer-links { display:flex; flex-wrap:wrap; gap:22px; }
        .footer-links a { color:#b7bdd0; font-size:11px; font-weight:700; }
        .footer-bottom { display:flex; justify-content:space-between; gap:20px; padding-top:20px; border-top:1px solid var(--line); color:#77809c; font-size:10px; }

        @media (max-width:900px) {
            .hero-inner { grid-template-columns:1fr .65fr; }
            .hero-art { min-height:320px; }
            .orbit { width:min(34vw,300px); }
            .venture { grid-template-columns:45px minmax(150px,.8fr) 1.2fr 38px; gap:15px; }
            .values { grid-template-columns:repeat(2,1fr); row-gap:25px; }
            .value:nth-child(2) { border-right:0; }
        }
        @media (max-width:700px) {
            .wrap { width:min(var(--max),calc(100% - 38px)); }
            .topline .wrap span:last-child { display:none; }
            .nav { min-height:72px; }
            .menu-toggle { display:block; }
            .nav-contact { display:none; }
            .nav-links {
                display:none; position:absolute; top:71px; left:0; right:0;
                padding:22px; background:var(--night-2);
                border:1px solid var(--line); flex-direction:column; align-items:flex-start;
            }
            .nav-links.open { display:flex; }
            .hero { padding-top:78px; }
            .hero-inner { grid-template-columns:1fr; }
            .hero h1 { font-size:clamp(53px,14vw,82px); }
            .hero-art { min-height:270px; margin-top:10px; }
            .orbit { width:230px; }
            .hero-foot { margin-top:42px; flex-wrap:wrap; }
            .intro-grid,.ai-layout,.future-layout { grid-template-columns:1fr; gap:28px; }
            .values { margin-top:42px; }
            .ventures-head { display:block; }
            .ventures-note { margin-top:20px; }
            .venture { grid-template-columns:32px 1fr 38px; gap:12px; padding:23px 0; }
            .venture p { grid-column:2 / 4; grid-row:2; }
            .venture-arrow { grid-column:3; grid-row:1; }
            .ai-stamp { min-height:260px; }
            .contact-inner { display:block; }
            .contact-copy { margin-top:28px; }
            .footer-top,.footer-bottom { flex-direction:column; }
        }
        @media (prefers-reduced-motion:reduce) {
            html { scroll-behavior:auto; }
            *,*::before,*::after { animation-duration:.01ms!important; transition-duration:.01ms!important; }
        }
    </style>
</head>
<body>
    <div class="topline">
        <div class="wrap">
            <span>Independent thinking. Connected ambition.</span>
            <span>Technology · Enterprise · Possibility</span>
        </div>
    </div>

    <header class="site-header">
        <div class="wrap nav">
            <a class="brand" href="#home" aria-label="Nexora Group home">
                <span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 48 48"><path d="M9 37V11h7l16 17V11h7v26h-7L16 20v17z" fill="currentColor"/><circle cx="38" cy="10" r="4" fill="#527bff"/></svg></span>
                <span class="brand-name">NEXORA<small>GROUP</small></span>
            </a>
            <nav class="nav-links" id="site-links" aria-label="Main navigation">
                <a href="#story">Our story</a>
                <a href="#ventures">Ventures</a>
                <a href="#intelligence">NexCore AI</a>
                <a href="#future">Our direction</a>
            </nav>
            <a class="nav-contact" href="#contact">LET’S TALK <span>↗</span></a>
            <button class="menu-toggle" id="menu-toggle" aria-label="Open navigation" aria-expanded="false" aria-controls="site-links">☰</button>
        </div>
    </header>

    <main id="home">
        <section class="hero">
            <div class="hero-grid" aria-hidden="true"></div>
            <div class="wrap">
                <div class="hero-inner">
                    <div>
                        <div class="kicker">A new kind of technology group</div>
                        <h1>Make what’s next <span class="outline">matter.</span></h1>
                        <p class="hero-copy">
                            We build technology ventures with a clear purpose:
                            connect people, unlock business potential and turn
                            ambitious ideas into useful, everyday solutions.
                        </p>
                        <div class="hero-actions">
                            <a class="button-primary" href="#ventures">EXPLORE OUR WORLD <span>↗</span></a>
                            <a class="text-link" href="#story">Get to know Nexora</a>
                        </div>
                    </div>
                    <div class="hero-art" aria-label="Abstract Nexora brand artwork">
                        <div class="orbit">
                            <span class="orbit-label one">Think beyond</span>
                            <div class="orbit-core">N</div>
                            <span class="orbit-label two">Build forward</span>
                        </div>
                    </div>
                </div>
                <div class="hero-foot">
                    <span>01 / A connected ecosystem</span>
                    <span>Connectivity — Growth — Intelligence</span>
                    <span>Built with purpose ↘</span>
                </div>
            </div>
        </section>

        <div class="ticker" aria-label="Nexora areas of focus">
            <div class="ticker-track" aria-hidden="true">
                <span>Ideas into impact</span><b>✳</b><span>Technology with purpose</span><b>✳</b><span>Business without borders</span><b>✳</b><span>Built for possibility</span><b>✳</b>
                <span>Ideas into impact</span><b>✳</b><span>Technology with purpose</span><b>✳</b><span>Business without borders</span><b>✳</b><span>Built for possibility</span><b>✳</b>
            </div>
        </div>

        <section class="section section-paper" id="story">
            <div class="wrap">
                <div class="intro-grid">
                    <div>
                        <div class="section-label">01 / The Nexora story</div>
                        <h2 class="section-title">One vision.<br><em>Many ways</em> to move forward.</h2>
                    </div>
                    <div class="intro-copy">
                        <p><strong>Nexora Group is the parent organisation behind a growing portfolio of technology ventures.</strong> We bring together entrepreneurship, practical innovation and long-term thinking to develop businesses that solve real problems.</p>
                        <p>Each venture has its own focus and identity. Together, they share a belief that well-built technology can open doors, strengthen communities and create lasting value.</p>
                    </div>
                </div>
                <div class="values">
                    <article class="value">
                        <span class="value-num">01 / PEOPLE FIRST</span>
                        <h3>Start with people</h3>
                        <p>Useful products begin with listening, understanding and solving genuine needs.</p>
                    </article>
                    <article class="value">
                        <span class="value-num">02 / MAKE IT BETTER</span>
                        <h3>Think differently</h3>
                        <p>We question the ordinary and look for clearer, more effective ways forward.</p>
                    </article>
                    <article class="value">
                        <span class="value-num">03 / EARN TRUST</span>
                        <h3>Build responsibly</h3>
                        <p>Security, accountability and transparency belong in the design from day one.</p>
                    </article>
                    <article class="value">
                        <span class="value-num">04 / LOOK AHEAD</span>
                        <h3>Grow with purpose</h3>
                        <p>We create with durability in mind, not just the next milestone.</p>
                    </article>
                </div>
            </div>
        </section>

        <section class="section" id="ventures">
            <div class="wrap">
                <div class="ventures-head">
                    <div>
                        <div class="section-label">02 / The portfolio</div>
                        <h2 class="section-title">Different missions.<br><em>Shared momentum.</em></h2>
                    </div>
                    <p class="ventures-note">A growing ecosystem of focused ventures, each built to take on a different opportunity in the digital world.</p>
                </div>
                <div class="venture-list">
                    <article class="venture">
                        <span class="venture-index">01</span>
                        <div class="venture-brand"><span class="venture-logo" aria-label="NexISP logo"><svg viewBox="0 0 32 32" aria-hidden="true"><path d="M4 12.5C10.8 6.7 21.2 6.7 28 12.5M8 17c4.6-3.9 11.4-3.9 16 0M12.2 21.2c2.2-1.8 5.4-1.8 7.6 0" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"/><circle cx="16" cy="25.5" r="2.1" fill="currentColor"/></svg></span><div><span class="venture-type">Connectivity & ISP technology</span><div class="venture-name">NexISP</div></div></div>
                        <p>Technology for internet service providers and connectivity businesses — helping manage customers, networks, service delivery and growth. NetPulse WiFi is the first operating business tenant.</p>
                        <a class="venture-arrow" href="#contact" aria-label="Enquire about NexISP">↗</a>
                    </article>
                    <article class="venture">
                        <span class="venture-index">02</span>
                        <div class="venture-brand"><span class="venture-logo" aria-label="Loopza logo"><svg viewBox="0 0 32 32" aria-hidden="true"><path d="M16 5.5c5.8 0 10.5 4.7 10.5 10.5S21.8 26.5 16 26.5 5.5 21.8 5.5 16 10.2 5.5 16 5.5Z" fill="none" stroke="currentColor" stroke-width="3"/><path d="M11 16h10M16 11v10" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/><circle cx="25.5" cy="7" r="3" fill="#25d9ff"/></svg></span><div><span class="venture-type">Marketing & business growth</span><div class="venture-name">Loopza</div></div></div>
                        <p>A digital marketing platform being developed to help businesses plan, manage and amplify their advertising across the channels where their audiences spend time.</p>
                        <a class="venture-arrow" href="#contact" aria-label="Enquire about Loopza">↗</a>
                    </article>
                    <article class="venture">
                        <span class="venture-index">03</span>
                        <div class="venture-brand"><span class="venture-logo" aria-label="NexCore AI logo"><svg viewBox="0 0 32 32" aria-hidden="true"><path d="M16 3.5 27 10v12L16 28.5 5 22V10z" fill="none" stroke="currentColor" stroke-width="2.2"/><circle cx="16" cy="16" r="4" fill="currentColor"/><path d="M16 7v5m0 8v5m-8-9h4m8 0h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></span><div><span class="venture-type">Shared intelligence initiative</span><div class="venture-name">NexCore AI</div></div></div>
                        <p>A shared intelligence foundation for the ecosystem, designed around useful assistance, responsible automation and clear human oversight.</p>
                        <a class="venture-arrow" href="#intelligence" aria-label="Explore NexCore AI">↗</a>
                    </article>
                </div>
            </div>
        </section>

        <section class="section ai-feature" id="intelligence">
            <div class="wrap ai-layout">
                <div>
                    <div class="section-label">03 / Intelligence, applied</div>
                    <h2 class="section-title">Meet the thinking layer: <em>NexCore AI.</em></h2>
                    <p class="ai-copy">We see artificial intelligence as a practical tool — one that should help people do better work, make sense of complex information and improve the services they rely on. NexCore AI is being developed as a shared foundation for the Nexora ecosystem, with permissions, privacy and human decisions at its centre.</p>
                    <p class="ai-copy">The ambition is not technology for its own sake. It is intelligence that makes each venture more useful.</p>
                </div>
                <div class="ai-stamp" aria-label="NexCore AI abstract symbol">
                    <span class="ai-glyph">✳</span>
                    <small>HUMAN DIRECTION. INTELLIGENT TOOLS.</small>
                </div>
            </div>
        </section>

        <section class="section future" id="future">
            <div class="wrap future-layout">
                <div>
                    <div class="section-label">04 / Looking ahead</div>
                    <h2 class="section-title">Built for the <em>long view.</em></h2>
                </div>
                <div class="future-copy">
                    <p>Our ambition is to grow a globally relevant technology group, grounded in real customer needs and shaped by the opportunities of an increasingly connected world.</p>
                    <p>That means building strong foundations, developing products with care, forming meaningful partnerships and entering new markets thoughtfully. We measure progress not only by how far we grow, but by the value our work creates.</p>
                    <div class="future-quote">“The future isn’t something we wait for. It’s something we build — with intention.”</div>
                </div>
            </div>
        </section>

        <section class="contact" id="contact">
            <div class="wrap contact-inner">
                <div>
                    <div class="section-label">05 / Start a conversation</div>
                    <h2 class="section-title">Have a big idea?<br><em>Let’s give it direction.</em></h2>
                </div>
                <div class="contact-copy">
                    We welcome conversations with businesses, innovators, technology partners and people who see an opportunity to build something meaningful.
                    <br>
                    <a class="contact-button" href="mailto:netanywhere254@gmail.com?subject=Nexora%20Group%20Enquiry">CONTACT NEXORA <span>↗</span></a>
                </div>
            </div>
        </section>
    </main>

    <footer class="footer">
        <div class="wrap">
            <div class="footer-top">
                <a class="brand" href="#home" aria-label="Nexora Group home">
                    <span class="brand-mark">N</span>
                    <span class="brand-name">NEXORA<small>GROUP</small></span>
                </a>
                <div class="footer-tag">Building technology. Connecting possibilities.</div>
                <nav class="footer-links" aria-label="Footer navigation">
                    <a href="#story">Our story</a>
                    <a href="#ventures">Ventures</a>
                    <a href="#intelligence">NexCore AI</a>
                    <a href="#contact">Contact</a>
                </nav>
            </div>
            <div class="footer-bottom">
                <span>© {{ date('Y') }} Nexora Group. All rights reserved.</span>
                <span>Independent thinking. Connected ambition.</span>
            </div>
        </div>
    </footer>

    <script>
        (() => {
            const button = document.getElementById('menu-toggle');
            const links = document.getElementById('site-links');

            button.addEventListener('click', () => {
                const isOpen = links.classList.toggle('open');
                button.setAttribute('aria-expanded', String(isOpen));
                button.setAttribute('aria-label', isOpen ? 'Close navigation' : 'Open navigation');
            });

            links.querySelectorAll('a').forEach((link) => {
                link.addEventListener('click', () => {
                    links.classList.remove('open');
                    button.setAttribute('aria-expanded', 'false');
                    button.setAttribute('aria-label', 'Open navigation');
                });
            });
        })();
    </script>
</body>
</html>
