<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#071426">
    <meta name="description" content="Nexora is a technology group building connected digital services across connectivity, business growth and intelligent software.">
    <title>Nexora — Technology that moves possibility forward</title>
    <style>
        :root{--ink:#071426;--panel:#0d2035;--panel2:#102941;--line:#24435f;--white:#eff7ff;--muted:#a6bad0;--cyan:#39c9f5;--blue:#3985ff;--lime:#b6f36b;--max:1160px}
        *{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:var(--ink);color:var(--white);font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;line-height:1.6}a{color:inherit;text-decoration:none}button,a{ -webkit-tap-highlight-color:transparent}.wrap{width:min(var(--max),calc(100% - 44px));margin:auto}
        .nav{height:82px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #ffffff16}.logo{display:flex;align-items:center;gap:12px;font-weight:850;letter-spacing:.17em}.symbol{width:35px;height:35px;border:2px solid var(--cyan);border-radius:11px;display:grid;place-items:center;color:var(--cyan);font-size:19px;transform:rotate(-8deg)}.logo span:last-child{font-size:15px}.links{display:flex;gap:30px;align-items:center;color:#c0d0df;font-size:13px}.links a:hover,.footer a:hover{color:var(--cyan)}.nav-cta,.btn{display:inline-flex;align-items:center;justify-content:center;padding:12px 18px;border-radius:9px;background:var(--cyan);color:#041522;font-weight:800;font-size:13px;border:1px solid var(--cyan);transition:.2s}.nav-cta:hover,.btn:hover{transform:translateY(-2px);filter:brightness(1.08)}.menu{display:none;background:none;border:1px solid var(--line);color:white;border-radius:8px;padding:8px 11px;font-size:20px}
        .hero{position:relative;overflow:hidden;padding:112px 0 105px;background:radial-gradient(ellipse at 78% 35%,#123d60 0,transparent 42%),linear-gradient(115deg,#071426 40%,#0a1e32 100%)}.hero:after{content:"";position:absolute;width:490px;height:490px;border:1px solid #45cafa22;border-radius:50%;right:-120px;top:5px;box-shadow:0 0 0 55px #45cafa08,0 0 0 110px #45cafa05;pointer-events:none}.eyebrow{color:var(--cyan);font-size:11px;font-weight:800;letter-spacing:.2em;text-transform:uppercase}.hero h1{font-size:clamp(44px,7vw,82px);line-height:1.02;letter-spacing:-.055em;max-width:820px;margin:22px 0}.hero h1 em{font-style:normal;color:var(--cyan)}.hero p{max-width:620px;color:var(--muted);font-size:18px;line-height:1.8;margin:0 0 30px}.actions{display:flex;gap:13px;flex-wrap:wrap}.btn.secondary{background:transparent;color:var(--white);border-color:#4a6680}.hero-note{margin-top:55px;color:#819bb4;font-size:12px;letter-spacing:.04em}
        section{padding:88px 0}.section-head{display:flex;justify-content:space-between;gap:30px;align-items:end;margin-bottom:34px}.section-head h2{font-size:clamp(30px,4vw,46px);letter-spacing:-.04em;line-height:1.1;margin:10px 0 0}.section-head p{max-width:430px;color:var(--muted);margin:0}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}.card{background:linear-gradient(145deg,#102941,#0a1b2e);border:1px solid var(--line);border-radius:17px;padding:27px;min-height:265px;display:flex;flex-direction:column;transition:.25s}.card:hover{transform:translateY(-5px);border-color:#3abfe9}.card-icon{width:45px;height:45px;border-radius:13px;background:#173b56;color:var(--cyan);display:grid;place-items:center;font-size:22px;font-weight:800}.card h3{font-size:22px;margin:24px 0 8px}.card p{color:var(--muted);font-size:14px;margin:0 0 20px}.card a{margin-top:auto;color:var(--cyan);font-size:13px;font-weight:800}.card .sub{color:#819bb4;font-size:10px;letter-spacing:.15em;text-transform:uppercase;margin-top:4px}
        .purpose{background:#0a1b2d;border-block:1px solid #ffffff12}.purpose-grid{display:grid;grid-template-columns:1fr 1fr;gap:70px;align-items:center}.purpose h2{font-size:clamp(32px,4vw,48px);line-height:1.12;letter-spacing:-.04em;margin:14px 0}.purpose p{color:var(--muted)}.principles{display:grid;gap:13px}.principle{display:flex;gap:16px;padding:17px;border:1px solid var(--line);border-radius:12px;background:#0c2238}.num{color:var(--cyan);font-size:12px;font-weight:900;letter-spacing:.1em}.principle strong{display:block}.principle span:last-child{color:var(--muted);font-size:13px}
        .ai{padding:0 0 90px}.ai-panel{border:1px solid #2c5c79;border-radius:20px;padding:clamp(25px,5vw,55px);background:radial-gradient(ellipse at 90% 10%,#164d6b 0,transparent 48%),linear-gradient(120deg,#102941,#0a1b2e);display:flex;justify-content:space-between;align-items:center;gap:30px}.ai-panel h2{font-size:clamp(30px,4vw,43px);line-height:1.1;letter-spacing:-.04em;margin:12px 0}.ai-panel p{color:var(--muted);max-width:620px;margin:0}.ai-mark{flex:0 0 110px;width:110px;height:110px;border-radius:32px;border:1px solid #4ac9f577;display:grid;place-items:center;color:var(--cyan);font-size:44px;box-shadow:0 0 60px #32c4f522}
        .contact{padding:75px 0;border-top:1px solid #ffffff14}.contact-inner{display:flex;justify-content:space-between;align-items:center;gap:25px}.contact h2{font-size:clamp(30px,4vw,44px);letter-spacing:-.04em;margin:8px 0}.contact p{color:var(--muted);margin:0}.footer{border-top:1px solid #ffffff14;padding:28px 0;color:#819bb4;font-size:12px}.footer-inner{display:flex;justify-content:space-between;gap:20px;flex-wrap:wrap}.footer-links{display:flex;gap:20px}
        @media(max-width:760px){.links{display:none;position:absolute;z-index:5;top:72px;left:0;right:0;padding:22px;background:#091a2d;border-bottom:1px solid var(--line);flex-direction:column;align-items:flex-start;gap:18px}.links.open{display:flex}.menu{display:block}.nav-cta{display:none}.hero{padding:82px 0 70px}.hero:after{right:-310px}.hero p{font-size:16px}.section-head{display:block}.section-head p{margin-top:16px}.grid{grid-template-columns:1fr}.card{min-height:220px}.purpose-grid{grid-template-columns:1fr;gap:28px}.ai-panel{align-items:flex-start}.ai-mark{flex-basis:70px;width:70px;height:70px;border-radius:22px;font-size:30px}.contact-inner{align-items:flex-start;flex-direction:column}section{padding:68px 0}}
        @media(prefers-reduced-motion:reduce){html{scroll-behavior:auto}*,*:before,*:after{transition:none!important}}
    </style>
</head>
<body>
<header class="wrap nav">
    <a class="logo" href="#top" aria-label="Nexora home"><span class="symbol">N</span><span>NEXORA</span></a>
    <nav class="links" id="site-links" aria-label="Main navigation">
        <a href="#about">About</a><a href="#companies">Our companies</a><a href="#intelligence">NexCore AI</a><a href="#contact">Contact</a>
    </nav>
    <a class="nav-cta" href="#companies">Explore the ecosystem ↗</a>
    <button class="menu" id="menu" aria-label="Toggle navigation" aria-expanded="false">☰</button>
</header>
<main id="top">
    <section class="hero">
        <div class="wrap">
            <div class="eyebrow">A technology group · Built for what’s next</div>
            <h1>Technology that moves <em>possibility</em> forward.</h1>
            <p>Nexora is building a connected ecosystem of digital businesses — bringing people online, helping businesses grow and making intelligent technology more useful.</p>
            <div class="actions"><a class="btn" href="#companies">Discover Nexora ↗</a><a class="btn secondary" href="#about">Our vision</a></div>
            <div class="hero-note">CONNECTIVITY &nbsp;·&nbsp; DIGITAL GROWTH &nbsp;·&nbsp; INTELLIGENCE</div>
        </div>
    </section>
    <section id="about">
        <div class="wrap section-head">
            <div><div class="eyebrow">Who we are</div><h2>One group. A world of<br>connected opportunities.</h2></div>
            <p>We bring focused technology ventures together under one group — each with its own purpose, products and customers, supported by shared foundations and a long-term view.</p>
        </div>
        <div class="wrap purpose-grid">
            <div><div class="eyebrow">Our direction</div><h2>Build useful technology. Create meaningful progress.</h2><p>Our approach is practical: solve real problems, make digital services easier to access and build dependable products that can grow with the people and businesses that use them.</p></div>
            <div class="principles">
                <div class="principle"><span class="num">01</span><div><strong>Customer-led</strong><span>Start with real needs and design around the people we serve.</span></div></div>
                <div class="principle"><span class="num">02</span><div><strong>Connected by design</strong><span>Build ventures that can collaborate while retaining clear responsibilities.</span></div></div>
                <div class="principle"><span class="num">03</span><div><strong>Responsible innovation</strong><span>Put security, accountability and trust at the centre of technology.</span></div></div>
            </div>
        </div>
    </section>
    <section id="companies" class="purpose">
        <div class="wrap">
            <div class="section-head"><div><div class="eyebrow">The Nexora ecosystem</div><h2>Distinct ventures.<br>Shared ambition.</h2></div><p>Explore the businesses and technology initiatives being developed within the Nexora ecosystem.</p></div>
            <div class="grid">
                <article class="card"><div class="card-icon">⌁</div><div class="sub">Connectivity & ISP technology</div><h3>NexISP</h3><p>Internet service provider management and connectivity technology, with NetPulse WiFi as the first operating business tenant.</p><a href="https://netanywhere254.com" aria-label="Visit NetAnywhere website">NetPulse / Net.Anywhere254 ↗</a></article>
                <article class="card"><div class="card-icon">↗</div><div class="sub">Business growth technology</div><h3>Loopza</h3><p>A marketing technology platform designed to help businesses plan, manage and amplify their digital advertising.</p><a href="#contact">Explore Loopza ↗</a></article>
                <article class="card"><div class="card-icon">✳</div><div class="sub">Shared intelligence layer</div><h3>NexCore AI</h3><p>A shared AI foundation intended to support products and teams with useful assistance under clear access and approval controls.</p><a href="#intelligence">Discover NexCore AI ↗</a></article>
            </div>
        </div>
    </section>
    <section class="ai" id="intelligence">
        <div class="wrap ai-panel">
            <div><div class="eyebrow">Intelligence across the ecosystem</div><h2>Meet NexCore AI.</h2><p>A shared intelligence initiative for the Nexora ecosystem. Its direction is to make assistance more relevant to each business while respecting organisational boundaries, privacy and human oversight.</p></div>
            <div class="ai-mark" aria-hidden="true">✳</div>
        </div>
    </section>
    <section id="contact" class="contact">
        <div class="wrap contact-inner"><div><div class="eyebrow">Start a conversation</div><h2>Let’s build what’s next.</h2><p>Interested in Nexora, our ventures or a potential collaboration?</p></div><a class="btn" href="mailto:netanywhere254@gmail.com?subject=Nexora%20Group%20Enquiry">Contact the group ↗</a></div>
    </section>
</main>
<footer class="footer"><div class="wrap footer-inner"><span>© {{ date('Y') }} Nexora Group. All rights reserved.</span><div class="footer-links"><a href="#about">About</a><a href="#companies">Our companies</a><a href="#contact">Contact</a></div><span>Technology that moves possibility forward.</span></div></footer>
<script>
(() => {
    const button=document.getElementById('menu'), links=document.getElementById('site-links');
    button.addEventListener('click',()=>{const open=links.classList.toggle('open');button.setAttribute('aria-expanded',String(open));});
    links.querySelectorAll('a').forEach(link=>link.addEventListener('click',()=>{links.classList.remove('open');button.setAttribute('aria-expanded','false');}));
})();
</script>
</body>
</html>
