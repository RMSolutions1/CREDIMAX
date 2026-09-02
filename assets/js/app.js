document.addEventListener('DOMContentLoaded', () => {
  const btn = document.querySelector('[data-toggle-sidebar]');
  const sidebar = document.querySelector('.sidebar');
  if (btn && sidebar) {
    btn.addEventListener('click', () => sidebar.classList.toggle('open'));
    document.addEventListener('click', (e) => {
      if (!sidebar.classList.contains('open')) return;
      if (sidebar.contains(e.target) || btn.contains(e.target)) return;
      sidebar.classList.remove('open');
    });
  }

  // Marketing mobile toggle
  const mToggle = document.querySelector('[data-toggle-mnav]');
  const mNav = document.querySelector('[data-mnav]');
  if (mToggle && mNav) {
    mToggle.addEventListener('click', () => {
      const open = mNav.classList.toggle('open');
      mToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  // Marketing dropdowns
  document.querySelectorAll('.m-dd').forEach((dd) => {
    const trigger = dd.querySelector('.m-dd-btn');
    if (!trigger) return;
    trigger.addEventListener('click', (e) => {
      e.stopPropagation();
      const willOpen = !dd.classList.contains('open');
      document.querySelectorAll('.m-dd.open').forEach((other) => {
        if (other !== dd) {
          other.classList.remove('open');
          other.querySelector('.m-dd-btn')?.setAttribute('aria-expanded', 'false');
        }
      });
      dd.classList.toggle('open', willOpen);
      trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    });
  });
  document.addEventListener('click', () => {
    document.querySelectorAll('.m-dd.open').forEach((dd) => {
      dd.classList.remove('open');
      dd.querySelector('.m-dd-btn')?.setAttribute('aria-expanded', 'false');
    });
  });

  // App sidebar accordion groups
  document.querySelectorAll('.nav-group-btn').forEach((btnGroup) => {
    btnGroup.addEventListener('click', () => {
      btnGroup.parentElement?.classList.toggle('open');
    });
  });

  // Highlight current app link
  const path = window.location.pathname.replace(/\/$/, '') || '/';
  document.querySelectorAll('.side-nav a[href]').forEach((a) => {
    try {
      const hrefPath = new URL(a.href, window.location.origin).pathname.replace(/\/$/, '') || '/';
      if (path === hrefPath || (hrefPath !== '/' && path.endsWith(hrefPath))) {
        a.classList.add('is-active');
        a.closest('.nav-group')?.classList.add('open');
      }
    } catch (_) {}
  });

  // Reveal on scroll (lo que ya está en pantalla se muestra al toque)
  const reveals = document.querySelectorAll('[data-reveal]');
  if (reveals.length && 'IntersectionObserver' in window) {
    const io = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-visible');
          io.unobserve(entry.target);
        }
      });
    }, { threshold: 0.08, rootMargin: '0px 0px -8% 0px' });
    reveals.forEach((el) => {
      const top = el.getBoundingClientRect().top;
      if (top < window.innerHeight * 0.92) {
        el.classList.add('is-visible');
      } else {
        io.observe(el);
      }
    });
  } else {
    reveals.forEach((el) => el.classList.add('is-visible'));
  }

  // Sticky CTA after hero (reserva espacio para no tapar botones)
  const sticky = document.querySelector('[data-sticky-cta]');
  const hero = document.querySelector('.hero');
  if (sticky && hero) {
    const toggleSticky = () => {
      const passed = window.scrollY > hero.offsetHeight * 0.65;
      sticky.hidden = !passed;
      document.body.classList.toggle('has-sticky-cta', passed);
    };
    toggleSticky();
    window.addEventListener('scroll', toggleSticky, { passive: true });
  }
});
