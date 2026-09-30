import React, {useState} from 'react';
import {useForm} from '@inertiajs/react';
import {ShieldCheck,Users,Save,UserPlus} from 'lucide-react';
import {Card,Field} from './UI';

const defaultModules=modules=>Object.keys(modules).filter(key=>!['employees','attendance','payrolls'].includes(key));

export default function AdminManagement({data}) {
 const [selected,setSelected]=useState(defaultModules(data.module_options));
 const form=useForm({name:'',email:'',password:'',modules:selected});
 const toggle=key=>{const next=selected.includes(key)?selected.filter(item=>item!==key):[...selected,key];setSelected(next);form.setData('modules',next)};
 const submit=e=>{e.preventDefault();form.post('/admin-management/users',{preserveScroll:true,onSuccess:()=>{form.reset('name','email','password');const defaults=defaultModules(data.module_options);setSelected(defaults);form.setData('modules',defaults)}})};

 return <div className="admin-management-grid"><Card title="Create an admin" subtitle="Set login credentials and choose the modules this admin can access." action={<ShieldCheck size={20}/> }><form className="admin-create-form" onSubmit={submit}><Field label="Admin name" error={form.errors.name}><input required maxLength={120} autoComplete="name" value={form.data.name} onChange={e=>form.setData('name',e.target.value)}/></Field><Field label="Email address" error={form.errors.email}><input type="email" required autoComplete="email" value={form.data.email} onChange={e=>form.setData('email',e.target.value)}/></Field><Field label="Temporary password (12+ characters)" error={form.errors.password}><input type="password" required minLength={12} autoComplete="new-password" value={form.data.password} onChange={e=>form.setData('password',e.target.value)}/></Field><ModulePicker modules={data.module_options} selected={selected} toggle={toggle}/>{Object.keys(form.errors).filter(k=>k.startsWith('modules')).length>0&&<div className="form-errors">{Object.entries(form.errors).filter(([key])=>key.startsWith('modules')).map(([key,error])=><p key={key}>{error}</p>)}</div>}<button className="button primary" disabled={form.processing}><UserPlus size={16}/>{form.processing?'Creating admin…':'Create admin'}</button></form></Card><div className="admin-list"><div className="admin-list-heading"><span className="stat-icon"><Users size={18}/></span><div><h2>Admin accounts</h2><p>Adjust module access at any time.</p></div></div>{data.admins.length?data.admins.map(admin=><AdminAccessCard key={admin.id} admin={admin} modules={data.module_options}/>):<Card title="No admin accounts yet"><p>Create the first admin account and select their workspace access.</p></Card>}</div></div>;
}

function ModulePicker({modules,selected,toggle}) {
 return <div className="module-picker"><div className="section-label">Module access <span>{selected.length} enabled</span></div><div className="module-toggle-grid">{Object.entries(modules).map(([key,label])=><label className="module-toggle" key={key}><input type="checkbox" checked={selected.includes(key)} onChange={()=>toggle(key)}/><span>{label}</span></label>)}</div><small>Employees, attendance, and payroll start disabled. Enable them when this admin needs HR access.</small></div>;
}

function AdminAccessCard({admin,modules}) {
 const [selected,setSelected]=useState(admin.modules);
 const form=useForm({modules:selected});
 const toggle=key=>{const next=selected.includes(key)?selected.filter(item=>item!==key):[...selected,key];setSelected(next);form.setData('modules',next)};
 return <Card title={admin.name} subtitle={admin.email} action={<span className="admin-module-count">{selected.length} modules</span>}><form onSubmit={e=>{e.preventDefault();form.put(`/admin-management/users/${admin.id}/modules`,{preserveScroll:true})}}><ModulePicker modules={modules} selected={selected} toggle={toggle}/><button className="button secondary" disabled={form.processing}><Save size={15}/>Save access</button></form></Card>;
}
