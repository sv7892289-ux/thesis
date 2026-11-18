'use strict';

const panelProfiles = {
  admin: {
    title: 'Panel de administración', scope: 'Gestión institucional', workspace: 'Todos los proyectos',
    description: 'Supervisa la institución, administra las cuentas y consulta la actividad del sistema.',
    focus: 'Una institución, una visión completa.',
    stats: [['Usuarios activos','users','users'],['Proyectos registrados','projects','library'],['Análisis completados','analyses','spark'],['Proyectos aprobados','approved','shield']],
    links: [['users','Usuarios y permisos','users'],['settings','Configuración','settings'],['audit','Auditoría','shield'],['projects','Repositorio institucional','library']]
  },
  coordinator: {
    title: 'Panel de coordinación', scope: 'Coordinación de carrera', workspace: 'Proyectos de mi carrera',
    description: 'Organiza la revisión de tu carrera, asigna docentes y supervisa los avances académicos.',
    focus: 'Cada proyecto, con el acompañamiento adecuado.',
    stats: [['Proyectos de mi carrera','projects','library'],['Sin revisor activo','unassigned','users'],['Revisores disponibles','reviewers','user'],['Proyectos aprobados','approved','shield']],
    links: [['workspace','Proyectos de mi carrera','library'],['history','Seguimiento de análisis','history'],['reports','Informes disponibles','report']]
  },
  reviewer: {
    title: 'Panel del docente revisor', scope: 'Revisión académica', workspace: 'Mis proyectos asignados',
    description: 'Consulta tus asignaciones, revisa los análisis y registra observaciones y dictámenes.',
    focus: 'Tu criterio convierte el análisis en orientación.',
    stats: [['Proyectos asignados','projects','library'],['Análisis por revisar','pending_reviews','clock'],['Con observaciones','observed','eye'],['Proyectos aprobados','approved','shield']],
    links: [['workspace','Mis proyectos asignados','library'],['search','Consultar antecedentes','search'],['reports','Informes disponibles','report']]
  },
  student: {
    title: 'Panel del estudiante', scope: 'Mi investigación', workspace: 'Mis proyectos',
    description: 'Consulta el avance de tus proyectos, carga documentos y atiende las observaciones docentes.',
    focus: 'Tu próxima entrega comienza aquí.',
    stats: [['Mis proyectos','projects','library'],['Mis documentos','documents','file'],['Mis análisis completados','analyses','spark'],['Proyectos con observaciones','observed','eye']],
    links: [['workspace','Mis proyectos','library'],['analyze','Analizar un documento','spark'],['search','Buscar antecedentes','search']]
  }
};
const currentPanel = () => panelProfiles[user.role] || panelProfiles.student;
function roleNavigation() {
  const base = [['dashboard','grid',currentPanel().title]];
  if (user.role !== 'admin') base.push(['workspace','file',currentPanel().workspace]);
  return [...base, ...navigation.filter(([path]) => path !== 'dashboard')];
}
function panelLinks(items) {
  return items.map(([href,label,ico]) => `<a class="role-action" href="#/${e(href)}"><span class="file-icon">${icon(ico)}</span><strong>${e(label)}</strong>${icon('chevron')}</a>`).join('');
}
function reviewQueue(items) {
  return items.length ? items.map(a => `<a class="role-action" href="#/analysis/${Number(a.id)}"><span class="file-icon">${icon('eye')}</span><div><strong>${e(a.title)}</strong><small>Análisis #${Number(a.id)} · ${date(a.created_at)}</small></div>${icon('chevron')}</a>`).join('') : empty('No hay revisiones pendientes','Aquí aparecerán los análisis completados que requieren revisión docente.');
}
async function roleDashboard() {
  const d = await api('dashboard'), p = currentPanel(), s = d.stats;
  const career = careers.find(c => Number(c.id) === Number(user.career_id));
  const subtitle = `${p.description}${user.role !== 'admin' && career ? ' Carrera: '+career.name+'.' : ''}`;
  const create = ['admin','student'].includes(user.role) ? button('Nuevo proyecto','plus','primary','data-new-project') : '';
  const cards = p.stats.map(([label,key,ico]) => `<div class="stat-card"><div class="stat-top"><span>${e(label)}</span><div class="stat-icon">${icon(ico)}</div></div><div class="stat-main"><strong>${Number(s[key] || 0)}</strong></div><div class="stat-foot">${e(p.scope)}</div></div>`).join('');
  let taskPanel = '';
  if (['admin','coordinator'].includes(user.role)) {
    taskPanel = `<section class="panel"><div class="panel-head"><div><h2>Asignar docentes revisores</h2><p>Proyectos abiertos sin revisor activo</p></div>${icon('users')}</div><div class="panel-body">${d.unassigned.length ? d.unassigned.map(x => `<a class="role-action" href="#/project/${Number(x.id)}"><strong>${e(x.title)}</strong><span>Asignar ${icon('arrow')}</span></a>`).join('') : empty('Asignaciones al día','Todos los proyectos abiertos tienen un revisor activo, o todavía no hay proyectos.')}</div></section>`;
  } else if (user.role === 'reviewer') {
    taskPanel = `<section class="panel"><div class="panel-head"><div><h2>Mis revisiones pendientes</h2><p>Análisis de proyectos asignados que aún no has revisado</p></div>${icon('clock')}</div><div class="panel-body">${reviewQueue(d.queue)}</div></section>`;
  } else {
    taskPanel = `<section class="panel"><div class="panel-head"><div><h2>Observaciones de mis docentes</h2><p>Revisiones recibidas en tus proyectos</p></div>${icon('eye')}</div><div class="panel-body">${d.feedback.length ? d.feedback.map(r => `<article class="review-card"><a href="#/analysis/${Number(r.analysis_id)}"><h3>${e(r.title)}</h3></a>${badge(r.verdict)}<p class="role-feedback">${e(r.observations)}</p><small>${e(r.reviewer)} · ${date(r.created_at)}</small></article>`).join('') : empty('Aún no recibiste observaciones','Registra tu proyecto, sube el documento y solicita un análisis para su revisión.')}</div></section>`;
  }
  const recent = `<section class="panel"><div class="panel-head"><div><h2>${e(p.workspace)}</h2><p>Hasta ocho proyectos, ordenados por actualización</p></div><a href="#/${user.role === 'admin' ? 'projects' : 'workspace'}">Ver todos ${icon('arrow')}</a></div>${d.recent.length ? `<div class="table-wrap"><table><thead><tr><th>PROYECTO / AUTOR</th><th>CARRERA</th><th>ESTADO</th><th></th></tr></thead><tbody>${projectRows(d.recent)}</tbody></table></div>` : empty(user.role === 'reviewer' ? 'Todavía no tienes asignaciones' : 'Todavía no hay proyectos',user.role === 'reviewer' ? 'Tu coordinador asignará aquí los proyectos que debes revisar.' : 'Los proyectos de tu espacio aparecerán aquí.')}</section>`;
  const extra = user.role === 'admin' ? `<section class="panel"><div class="panel-head"><h3>Actividad institucional</h3><a href="#/audit">Ver auditoría</a></div><div class="panel-body">${d.activity.map(a => `<div class="activity-item"><div class="activity-icon">${icon('clock')}</div><div><p>${e(a.action)}</p><small>${e(a.actor || 'Sistema')} · ${date(a.created_at)}</small></div></div>`).join('') || '<p class="muted">Todavía no hay actividad.</p>'}</div></section>` : user.role === 'coordinator' ? `<section class="panel"><div class="panel-head"><h3>Seguimiento de revisiones</h3></div><div class="panel-body">${reviewQueue(d.queue)}</div></section>` : '';
  return {html: `<div class="role-dashboard role-${e(user.role)}">${heading(e(p.title),e(subtitle),create)}<section class="role-welcome"><div><span class="eyebrow">${e(p.scope)}</span><h2>${e(p.focus)}</h2><p>Hola, ${e(user.name)}.</p></div><span class="role-emblem">${icon({admin:'settings',coordinator:'users',reviewer:'shield',student:'book'}[user.role])}</span></section><section class="stats" aria-label="Estadísticas de mi panel">${cards}</section><div class="dashboard-grid"><div class="stack">${taskPanel}${recent}</div><aside class="right-column"><section class="panel"><div class="panel-head"><h3>Accesos de ${e(roles[user.role].toLowerCase())}</h3></div><div class="panel-body">${panelLinks(p.links)}</div></section>${extra}</aside></div></div>`,bind:bindNewProject};
}
function assignmentPanel(d) {
  return `<section class="panel"><div class="panel-head"><h3>Docentes asignados</h3>${icon('users')}</div><div class="panel-body">${d.reviewers.length ? d.reviewers.map(r => `<div class="key-value"><span>${e(r.name)}</span><strong>${Number(r.active) ? 'Asignado' : 'Cuenta inactiva'}</strong></div>`).join('') : '<p class="prose">Coordinación aún no ha asignado un docente revisor.</p>'}${d.can_assign ? '<button type="button" class="button primary" id="manage-reviewers">Asignar revisores</button>' : ''}</div></section>`;
}
function bindAssignments(id) {
  $('#manage-reviewers')?.addEventListener('click',async () => {
    try {
      const d=await api('assignments',{id});
      showModal('Asignación de docentes revisores',`<p class="prose">Selecciona un docente activo de la carrera del proyecto.</p><form id="assign-reviewer-form" class="form-stack"><div class="field"><label for="assigned-reviewer">Docente revisor</label><select id="assigned-reviewer" name="reviewer_id" required><option value="">Seleccionar docente</option>${d.available.map(r=>`<option value="${Number(r.id)}">${e(r.name)} · ${e(r.email)}</option>`).join('')}</select></div>${!d.available.length ? notice('No hay revisores activos en esta carrera. El administrador debe crear o activar una cuenta de docente revisor.') : ''}<p class="form-error" role="alert"></p><button type="submit" class="button primary" ${!d.available.length?'disabled':''}>Guardar asignación</button></form><div class="role-assigned-list">${d.assigned.map(r=>`<div class="key-value"><span>${e(r.name)}</span><button type="button" class="button small" data-remove-reviewer="${Number(r.id)}">Retirar</button></div>`).join('')}</div>`);
      bindForm('#assign-reviewer-form',async data=>{await api('assignments',{method:'POST',id,body:{reviewer_id:Number(data.reviewer_id)}});modal.close();toast('Docente asignado.');route();});
      modal.querySelectorAll('[data-remove-reviewer]').forEach(b=>b.onclick=async()=>{
        b.disabled=true;
        try{await api('assignments',{method:'POST',id,body:{reviewer_id:Number(b.dataset.removeReviewer),remove:true}});modal.close();toast('Asignación retirada.');route();}catch(err){toast(err.message,true);b.disabled=false;}
      });
    } catch(err) {toast(err.message,true);}
  });
}
