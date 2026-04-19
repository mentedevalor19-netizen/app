    </main>
  </div>
</div>

<script>
const sidebar = document.getElementById('sidebar');
const sidebarBackdrop = document.getElementById('sidebarBackdrop');
const menuToggle = document.getElementById('menuToggle');

function openSidebar() {
  if (!sidebar || !sidebarBackdrop) {
    return;
  }

  sidebar.classList.add('open');
  sidebarBackdrop.classList.add('open');
}

function closeSidebar() {
  if (!sidebar || !sidebarBackdrop) {
    return;
  }

  sidebar.classList.remove('open');
  sidebarBackdrop.classList.remove('open');
}

if (menuToggle) {
  menuToggle.addEventListener('click', function () {
    if (sidebar.classList.contains('open')) {
      closeSidebar();
    } else {
      openSidebar();
    }
  });
}

if (sidebarBackdrop) {
  sidebarBackdrop.addEventListener('click', closeSidebar);
}

document.querySelectorAll('.modal-overlay').forEach(function (overlay) {
  overlay.addEventListener('click', function (event) {
    if (event.target === overlay) {
      overlay.classList.remove('open');
    }
  });
});

function openModal(id) {
  const element = document.getElementById(id);
  if (element) {
    element.classList.add('open');
  }
}

function closeModal(id) {
  const element = document.getElementById(id);
  if (element) {
    element.classList.remove('open');
  }
}

const clock = document.getElementById('clock');
if (clock) {
  const updateClock = function () {
    const now = new Date();
    const pad = function (value) {
      return String(value).padStart(2, '0');
    };

    clock.textContent = pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());
  };

  updateClock();
  window.setInterval(updateClock, 1000);
}
</script>
</body>
</html>
