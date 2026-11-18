const fs=require('node:fs');
const assert=require('node:assert/strict');
const {chromium}=require('@playwright/test');
if (!process.argv[2] || !process.env.TEST_URL) {
 console.error('Indica TEST_URL y el archivo de estado de una instalación de pruebas aislada. Consulta docs/PRUEBAS.md.');
 process.exit(1);
}
const state=JSON.parse(fs.readFileSync(process.argv[2],'utf8'));
const base=process.env.TEST_URL.endsWith('/') ? process.env.TEST_URL : process.env.TEST_URL+'/';
const artifacts='tests/artifacts/role-panels';
fs.mkdirSync(artifacts,{recursive:true});
const titles={admin:'Panel de administración',coordinator:'Panel de coordinación',reviewer:'Panel del docente revisor',student:'Panel del estudiante'};
(async()=>{
 const browser=await chromium.launch({channel:'msedge',headless:true});
 const pages={},errors=[];let passed=0;
 const check=(ok,label)=>{assert.ok(ok,label);passed++;console.log('OK '+label);};
 const call=async(page,action,method='GET',body=null,id=null)=>page.evaluate(async({action,method,body,id})=>{
   const session=await(await fetch('api.php?action=session')).json();
   const r=await fetch('api.php?'+new URLSearchParams({action,...(id===null?{}:{id})}),{method,headers:{'Content-Type':'application/json','X-CSRF-Token':session.csrf},...(body===null?{}:{body:JSON.stringify(body)})});
   return {status:r.status,data:await r.json()};
 },{action,method,body,id});
 try{
 for(const role of Object.keys(titles)){
   const context=await browser.newContext({viewport:{width:1440,height:1000}}),page=await context.newPage();pages[role]=page;page.setDefaultTimeout(15000);console.log('LOGIN '+role);
   page.on('pageerror',e=>errors.push(e.message));
   await page.goto(base,{waitUntil:'domcontentloaded'});
   await page.getByLabel('Correo electrónico',{exact:true}).fill(role+'@test.local');
   await page.getByLabel('Contraseña',{exact:true}).fill(state.password);
   await page.getByRole('button',{name:'Entrar a mi espacio'}).click();
   await page.getByRole('heading',{name:titles[role],exact:true}).waitFor();
   const dashboard=await call(page,'dashboard');
   check(dashboard.status===200,'Panel '+role+' carga datos');
   const expected={admin:3,coordinator:2,reviewer:0,student:1};
   check(Number(dashboard.data.stats.projects)===expected[role],'Estadísticas propias de '+role);
   const users=await call(page,'users');
   check(users.status===(role==='admin'?200:403),'Restricción de usuarios para '+role);
   if(role!=='admin')check(await page.locator('.sidebar a[href="#/users"]').count()===0,'Menú de administración oculto a '+role);
   await page.screenshot({path:`${artifacts}/${role}.png`,fullPage:true,timeout:15000});
 }
 const {admin,coordinator,reviewer,student}=pages,project=state.projects[0];
 check((await call(reviewer,'project','GET',null,project)).status===404,'Revisor sin asignación no ve proyecto privado');
 check((await call(student,'assignments','POST',{reviewer_id:state.users.reviewer},project)).status===403,'Estudiante no puede asignar');
 check((await call(reviewer,'assignments','POST',{reviewer_id:state.users.reviewer},state.projects[2])).status===403,'Revisor no puede autoasignarse');
 check((await call(coordinator,'assignments','POST',{reviewer_id:state.other},state.projects[1])).status===403,'Coordinador no asigna fuera de su carrera');
 check((await call(coordinator,'assignments','POST',{reviewer_id:state.other},project)).status===422,'No se asignan docentes de otra carrera');
 await coordinator.goto(base+'#/project/'+project);
 await coordinator.getByRole('button',{name:'Asignar revisores',exact:true}).click();
 await coordinator.getByLabel('Docente revisor',{exact:true}).selectOption(String(state.users.reviewer));
 await coordinator.getByRole('button',{name:'Guardar asignación',exact:true}).click();
 await coordinator.locator('dialog').waitFor({state:'hidden'});
 check((await call(reviewer,'project','GET',null,project)).status===200,'Asignación desde interfaz habilita proyecto privado');
 await reviewer.goto(base+'#/dashboard');
 await reviewer.getByRole('heading',{name:titles.reviewer,exact:true}).waitFor();
 const assigned=await call(reviewer,'dashboard');
 check(Number(assigned.data.stats.projects)===1&&Number(assigned.data.stats.pending_reviews)===1,'Panel del revisor refleja asignación y pendiente');
 check((await call(reviewer,'project','PATCH',{title:'Cambio no autorizado'},project)).status===403,'Revisor no modifica la ficha ajena');
 check((await call(student,'project','PATCH',{status:'aprobado'},project)).status===403,'Estudiante no aprueba su proyecto');
 check((await call(student,'review','POST',{verdict:'aprobado',observations:'No permitido',criteria:'No permitido'},state.analysis)).status===403,'Estudiante no registra dictamen');
 await reviewer.goto(base+'#/analysis/'+state.analysis);
 await reviewer.getByLabel('Observaciones',{exact:true}).fill('Precisar los objetivos antes de la próxima entrega.');
 await reviewer.getByLabel('Criterios y evidencia considerados').fill('Coherencia del planteamiento.');
 await reviewer.getByLabel('Dictamen',{exact:true}).selectOption('observado');
 await reviewer.getByRole('button',{name:'Guardar revisión',exact:true}).click();
 await reviewer.getByText('Precisar los objetivos antes de la próxima entrega.',{exact:true}).waitFor();
 check(Number((await call(reviewer,'dashboard')).data.stats.pending_reviews)===0,'Dictamen retira el análisis de pendientes');
 await student.goto(base+'#/dashboard');
 await student.getByText('Precisar los objetivos antes de la próxima entrega.',{exact:true}).waitFor();
 check(true,'Estudiante recibe observación en su panel');
 check((await call(coordinator,'assignments','POST',{reviewer_id:state.users.reviewer,remove:true},project)).status===200,'Coordinador puede retirar asignación');
 check((await call(reviewer,'review','POST',{verdict:'aprobado',observations:'No permitido',criteria:'No permitido'},state.analysis)).status===404,'Retirar asignación revoca acceso privado y dictamen');
 for(const [role,page]of Object.entries(pages)){
   await page.setViewportSize({width:390,height:844});await page.goto(base+'#/dashboard');
   await page.getByRole('heading',{name:titles[role],exact:true}).waitFor();
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Panel móvil sin desbordamiento: '+role);
 }
 check(errors.length===0,'Sin errores JavaScript');
 fs.writeFileSync(artifacts+'/result.json',JSON.stringify({passed,errors},null,2));
 console.log(passed+' comprobaciones aprobadas.');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
