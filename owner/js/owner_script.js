document.addEventListener('DOMContentLoaded', function () {
  const sidebar = document.querySelector('.sidebar');
  const overlay = document.querySelector('.overlay');
  const toggleBtn = document.querySelector('.sidebar-toggle');
  const closeBtn = document.querySelector('.sidebar-close');

  function openSidebar() {
    sidebar.classList.add('open');
    overlay.classList.add('show');
    document.body.classList.add('sidebar-open');
  }

  function closeSidebar() {
    sidebar.classList.remove('open');
    overlay.classList.remove('show');
    document.body.classList.remove('sidebar-open');
  }

  if (toggleBtn) {
    toggleBtn.addEventListener('click', function () {
      if (sidebar.classList.contains('open')) {
        closeSidebar();
      } else {
        openSidebar();
      }
    });
  }

  if (closeBtn) {
    closeBtn.addEventListener('click', closeSidebar);
  }

  if (overlay) {
    overlay.addEventListener('click', closeSidebar);
  }

  document.querySelectorAll('.sidebar-nav a').forEach(function (link) {
    link.addEventListener('click', function () {
      if (window.innerWidth <= 900) {
        closeSidebar();
      }
    });
  });
});
