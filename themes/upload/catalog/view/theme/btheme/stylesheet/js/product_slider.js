document.addEventListener('DOMContentLoaded', function(){
  (function(){
   var STEP = 220; // Slide ~1 card
   function update(slider, prev, next) {
     var max = slider.scrollWidth - slider.clientWidth - 1;
     if (max <= 0) {
       prev.classList.add('is-disabled');
       next.classList.add('is-disabled');
       slider.setAttribute('data-can-scroll', 'false');
       return;
     }
     slider.setAttribute('data-can-scroll', 'true');
     // Left
     if (slider.scrollLeft <= 2) {
       prev.classList.add('is-disabled');
     } else {
       prev.classList.remove('is-disabled');
     }
     // Right
     if (slider.scrollLeft >= max) {
       next.classList.add('is-disabled');
     } else {
       next.classList.remove('is-disabled');
     }
   }

document.querySelectorAll('.slider-wrapper').forEach(function(wrap) {
  var slider = wrap.querySelector('.products__slider');
  var prev = wrap.querySelector('.products__nav-btn.is-left');
  var next = wrap.querySelector('.products__nav-btn.is-right');

  // muted by default
  prev.classList.add('is-disabled');

  function go(dir) {
    slider.scrollBy({ left: dir * STEP, behavior: 'smooth' });
  }

  prev.addEventListener('click', function() { go(-1); });
  next.addEventListener('click', function() { go(1); });

  slider.addEventListener('scroll', function() {
    update(slider, prev, next);
  });

  var ro = new ResizeObserver(function() {
    update(slider, prev, next);
  });
  ro.observe(slider);

  // init
  update(slider, prev, next);
});

 })();
});
