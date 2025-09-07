document.addEventListener('DOMContentLoaded', function(){
  const slider   = document.getElementById('heroSlider');
  if(!slider) return;

  const slides   = Array.from(slider.querySelectorAll('.hero-slide'));
  const dotsWrap = document.getElementById('heroDots');
  const btnPrev  = slider.querySelector('.hero-nav.prev');
  const btnNext  = slider.querySelector('.hero-nav.next');

  // Build dots (sekali)
  if (dotsWrap && !dotsWrap.querySelector('.hero-dot')) {
    slides.forEach((_, i) => {
      const b = document.createElement('button');
      b.className = 'hero-dot' + (i===0 ? ' active':'');
      b.setAttribute('aria-label', 'Go to slide ' + (i+1));
      b.addEventListener('click', () => goTo(i, true));
      dotsWrap.appendChild(b);
    });
  }
  const dots = dotsWrap ? Array.from(dotsWrap.querySelectorAll('.hero-dot')) : [];

  let idx = 0, timer = null, typingTimer = null, ro = null;
  const AUTOPLAY_MS = 5000;
  const PAUSE_WHILE_TYPING = true; // kurangi “lonjakan” tinggi

  function cleanupTyping(){
    clearTimeout(typingTimer);
    typingTimer = null;
  }
  function cleanupObserver(){
    if (ro) { ro.disconnect(); ro = null; }
  }

  // Tinggi container selalu ikut slide aktif
  function observeActiveHeight(){
    cleanupObserver();
    const active = slides[idx];
    if(!active) return;
    ro = new ResizeObserver(() => {
      // gunakan scrollHeight agar ikut teks dinamis (typewriter)
      slider.style.height = active.scrollHeight + 'px';
    });
    ro.observe(active);
    // set awal
    slider.style.height = active.scrollHeight + 'px';
  }

  function typeText(el, text, speed=22){
    cleanupTyping();
    el.textContent = '';
    el.classList.add('typewriter');
    let i = 0;

    if (PAUSE_WHILE_TYPING) stopAutoplay();

    (function tick(){
      if(i <= text.length){
        el.textContent = text.slice(0, i++);
        typingTimer = setTimeout(tick, speed);
      } else {
        el.classList.remove('typewriter');
        typingTimer = null;
        if (PAUSE_WHILE_TYPING) restartAutoplay();
      }
    })();
  }

  function showSlide(i){
    // matikan semua
    slides.forEach((s,k)=>s.classList.toggle('active', k===i));
    if (dots.length) dots.forEach((d,k)=>d.classList.toggle('active', k===i));

    // ketik sub‑title
    const sub  = slides[i].querySelector('.h-sub');
    const text = slides[i].getAttribute('data-sub') || '';
    typeText(sub, text);

    // tinggi kontainer sinkron setelah DOM settle
    requestAnimationFrame(observeActiveHeight);
  }

  function goTo(i, manual=false){
    idx = (i + slides.length) % slides.length;
    cleanupTyping();       // hentikan typing slide lama
    showSlide(idx);
    if (manual) restartAutoplay();
  }
  const next = () => goTo(idx+1);
  const prev = () => goTo(idx-1);

  btnNext && btnNext.addEventListener('click', next);
  btnPrev && btnPrev.addEventListener('click', prev);

  // keyboard
  slider.setAttribute('tabindex','0');
  slider.addEventListener('keydown', (e)=>{
    if(e.key==='ArrowRight') next();
    if(e.key==='ArrowLeft')  prev();
  });

  // swipe
  let startX = 0;
  slider.addEventListener('touchstart', e=> startX = e.touches[0].clientX, {passive:true});
  slider.addEventListener('touchend', e=>{
    const dx = e.changedTouches[0].clientX - startX;
    if(Math.abs(dx) > 40){ dx < 0 ? next() : prev(); }
  }, {passive:true});

  // autoplay
  function startAutoplay(interval=AUTOPLAY_MS){ timer = setInterval(next, interval); }
  function stopAutoplay(){ clearInterval(timer); timer = null; }
  function restartAutoplay(){ stopAutoplay(); startAutoplay(); }

  slider.addEventListener('mouseenter', stopAutoplay);
  slider.addEventListener('mouseleave', restartAutoplay);
  window.addEventListener('resize', () => requestAnimationFrame(observeActiveHeight));

  // init
  showSlide(0);
  startAutoplay();



  // Language Switch
   $(function() {
        var $wrap  = $('#lang-switch');
        if (!$wrap.length) return;

        var $btn   = $wrap.find('.lang-current');
        var $menu  = $wrap.find('.lang-menu');
        var $code  = $wrap.find('input[name="code"]');
        var $form  = $('#form-language');

        // Toggle on click
        $btn.on('click', function(e) {
          e.preventDefault();
          var open = $menu.hasClass('open');
          $menu.toggleClass('open', !open);
          $btn.attr('aria-expanded', String(!open));
          if (!open) $menu.focus();
        });

        // Pick language (pakai name tombol sebagai code)
        $menu.on('click', '.language-select', function(e) {
          e.preventDefault();
          var langCode = $(this).attr('name') || $(this).data('code');
          if (!langCode) return;
          $code.val(langCode);
          $form.trigger('submit');
        });

        // Close on outside click
        $(document).on('click', function(e) {
          if (!$wrap.is(e.target) && $wrap.has(e.target).length === 0) {
            $menu.removeClass('open'); $btn.attr('aria-expanded', 'false');
          }
        });

        // Close on ESC
        $(document).on('keydown', function(e) {
          if (e.key === 'Escape') {
            $menu.removeClass('open'); $btn.attr('aria-expanded', 'false');
          }
        });
      });
});
