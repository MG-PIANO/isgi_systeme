async function loadStudents() {
  const result = await window.api.getStudents();

  if (!result.success) {
    console.error(result.message || 'Erreur lors du chargement');
    return;
  }

  renderStudents(result.students || []);
}

function renderStudents(students) {
  const container = document.getElementById('studentList');
  if (!container) return;

  container.innerHTML = '';

  if (!students.length) {
    container.innerHTML = '<div class="alert alert-info">Aucun étudiant trouvé.</div>';
    return;
  }

  students.forEach(student => {
    const item = document.createElement('div');
    item.className = 'student-item';

    item.innerHTML = `
      <div>
        <strong>${student.nom || ''} ${student.prenom || ''}</strong><br>
        <small>${student.matricule || 'Matricule non défini'}</small>
      </div>
      <div>${student.classe_nom || 'Aucune classe'}</div>
    `;

    container.appendChild(item);
  });
}

document.getElementById('searchInput')?.addEventListener('input', async (e) => {
  const q = e.target.value.trim();

  if (!q) {
    loadStudents();
    return;
  }

  const result = await window.api.searchStudents(q);

  if (result.success) {
    renderStudents(result.students || []);
  }
});

loadStudents();const QRCode = require('qrcode/lib/core/qrcode');

window.QRCode = { create: QRCode.create };
