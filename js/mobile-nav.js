/**
 * DJIHS Mobile Navigation - Slide-in Drawer
 * Include this script on every page that needs mobile nav.
 * Usage: <script src="../js/mobile-nav.js"></script>
 */

(function () {
  function initMobileNav() {
    const overlay = document.getElementById('mobileNavOverlay');
    const drawer = document.getElementById('mobileNavDrawer');
    const openBtn = document.getElementById('mobileNavOpen');
    const closeBtn = document.getElementById('mobileNavClose');

    if (!overlay || !drawer || !openBtn) return;

    function openDrawer() {
      overlay.classList.remove('opacity-0', 'pointer-events-none');
      overlay.classList.add('opacity-100');
      drawer.classList.remove('-translate-x-full');
      drawer.classList.add('translate-x-0');
      document.body.style.overflow = 'hidden';
    }

    function closeDrawer() {
      overlay.classList.remove('opacity-100');
      overlay.classList.add('opacity-0', 'pointer-events-none');
      drawer.classList.remove('translate-x-0');
      drawer.classList.add('-translate-x-full');
      document.body.style.overflow = '';
    }

    openBtn.addEventListener('click', openDrawer);
    if (closeBtn) closeBtn.addEventListener('click', closeDrawer);
    overlay.addEventListener('click', closeDrawer);

    // Close on nav link click (so the drawer closes when navigating)
    drawer.querySelectorAll('a').forEach(link => {
      link.addEventListener('click', closeDrawer);
    });

    // Close on Escape key
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') closeDrawer();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initMobileNav);
  } else {
    initMobileNav();
  }
})();
