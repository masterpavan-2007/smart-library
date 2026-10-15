// =====================================================================
// Theme Management (Dark / Light Mode)
// =====================================================================
window.toggleTheme = function () {
  const current = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
  const next = current === 'dark' ? 'light' : 'dark';

  document.documentElement.classList.add('theme-transitioning');
  document.documentElement.setAttribute('data-theme', next);

  try {
    localStorage.setItem('smart_library_theme', next);
  } catch (e) {}

  window.updateThemeUI(next);
  window.dispatchEvent(new CustomEvent('themechange', { detail: { theme: next } }));

  setTimeout(function () {
    document.documentElement.classList.remove('theme-transitioning');
  }, 350);
};

window.updateThemeUI = function (theme) {
  const isDark = theme === 'dark';
  
  // Update all theme toggle buttons
  document.querySelectorAll('#themeToggleBtn, .theme-toggle-btn').forEach(function (btn) {
    btn.setAttribute('aria-label', isDark ? 'Switch to Light Mode' : 'Switch to Dark Mode');
    btn.setAttribute('title', isDark ? 'Switch to Light Mode' : 'Switch to Dark Mode');
    const icon = btn.querySelector('i');
    if (icon) {
      if (isDark) {
        icon.className = 'fa-solid fa-sun theme-toggle-icon';
        icon.style.color = '#fbbf24';
      } else {
        icon.className = 'fa-solid fa-moon theme-toggle-icon';
        icon.style.color = '';
      }
    }
  });

  // Update user dropdown menu item text
  const menuText = document.getElementById('userMenuThemeText');
  if (menuText) {
    menuText.textContent = isDark ? 'Light Mode' : 'Dark Mode';
  }

  // Update receipt theme button if present
  const receiptBtn = document.getElementById('receiptThemeBtn');
  const receiptText = document.getElementById('receiptThemeText');
  if (receiptBtn && receiptText) {
    if (isDark) {
      receiptBtn.innerHTML = '<i class="fa-solid fa-sun" style="color:#fbbf24;"></i> <span id="receiptThemeText">Light Mode</span>';
    } else {
      receiptBtn.innerHTML = '<i class="fa-solid fa-moon"></i> <span id="receiptThemeText">Dark Mode</span>';
    }
  }
};

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
  // Initialize theme UI
  const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
  window.updateThemeUI(currentTheme);

  // Listen for system theme changes if no explicit user override is stored
  if (window.matchMedia) {
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
      if (!localStorage.getItem('smart_library_theme')) {
        const autoTheme = e.matches ? 'dark' : 'light';
        document.documentElement.setAttribute('data-theme', autoTheme);
        window.updateThemeUI(autoTheme);
        window.dispatchEvent(new CustomEvent('themechange', { detail: { theme: autoTheme } }));
      }
    });
  }
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
