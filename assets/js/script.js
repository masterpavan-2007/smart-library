window.toggleUserDropdown = function (e) {
  if (e) {
    if (typeof e.stopPropagation === 'function') e.stopPropagation();
  }
  const btn = document.getElementById('userMenuBtn');
  const menu = document.getElementById('userDropdownMenu');
  if (!btn || !menu) return;
  const isVisible = !menu.classList.contains('show');
  menu.classList.toggle('show', isVisible);
  btn.classList.toggle('active', isVisible);
  btn.setAttribute('aria-expanded', isVisible ? 'true' : 'false');
};

document.addEventListener('DOMContentLoaded', function () {
  // Sidebar toggle (mobile)
  const toggleBtn = document.querySelector('.menu-toggle');
  const sidebar = document.querySelector('.sidebar');
  if (toggleBtn && sidebar) {
    toggleBtn.addEventListener('click', () => sidebar.classList.toggle('open'));
    document.addEventListener('click', (e) => {
      if (window.innerWidth <= 768 && sidebar.classList.contains('open')
          && !sidebar.contains(e.target) && !toggleBtn.contains(e.target)) {
        sidebar.classList.remove('open');
      }
    });
  }

  // Close user dropdown on click outside
  document.addEventListener('click', (e) => {
    const btn = document.getElementById('userMenuBtn');
    const menu = document.getElementById('userDropdownMenu');
    if (btn && menu && menu.classList.contains('show')) {
      if (!btn.contains(e.target) && !menu.contains(e.target)) {
        menu.classList.remove('show');
        btn.classList.remove('active');
        btn.setAttribute('aria-expanded', 'false');
      }
    }
  });

  // Close user dropdown on Escape key
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      const btn = document.getElementById('userMenuBtn');
      const menu = document.getElementById('userDropdownMenu');
      if (btn && menu && menu.classList.contains('show')) {
        menu.classList.remove('show');
        btn.classList.remove('active');
        btn.setAttribute('aria-expanded', 'false');
        btn.focus();
      }
    }
  });

  // Password show/hide toggles
  document.querySelectorAll('.password-toggle').forEach((toggle) => {
    toggle.addEventListener('click', () => {
      const input = document.getElementById(toggle.dataset.target);
      if (!input) return;
      const isPassword = input.type === 'password';
      input.type = isPassword ? 'text' : 'password';
      toggle.classList.toggle('fa-eye');
      toggle.classList.toggle('fa-eye-slash');
    });
  });

  // Generic modal open/close via data attributes:
  // data-modal-open="modalId"  /  data-modal-close
  document.querySelectorAll('[data-modal-open]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const modal = document.getElementById(btn.dataset.modalOpen);
      if (modal) modal.classList.add('open');
    });
  });
  document.querySelectorAll('[data-modal-close]').forEach((btn) => {
    btn.addEventListener('click', () => {
      btn.closest('.modal-overlay')?.classList.remove('open');
    });
  });
  document.querySelectorAll('.modal-overlay').forEach((overlay) => {
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) overlay.classList.remove('open');
    });
  });

  // Confirm-before-submit for delete / destructive forms
  document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (e) => {
      const msg = form.dataset.confirm || 'Are you sure?';
      if (!confirm(msg)) e.preventDefault();
    });
  });

  // Auto-dismiss flash alerts after 5s
  document.querySelectorAll('.alert[data-autohide]').forEach((alert) => {
    setTimeout(() => { alert.style.display = 'none'; }, 5000);
  });

  // Simple client-side table search: data-search-table + input[data-search-input]
  document.querySelectorAll('[data-search-input]').forEach((input) => {
    const tableId = input.dataset.searchInput;
    const table = document.getElementById(tableId);
    if (!table) return;
    input.addEventListener('input', () => {
      const term = input.value.trim().toLowerCase();
      table.querySelectorAll('tbody tr').forEach((row) => {
        row.style.display = row.textContent.toLowerCase().includes(term) ? '' : 'none';
      });
    });
  });
});
