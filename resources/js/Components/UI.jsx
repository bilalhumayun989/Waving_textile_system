import React, { useState } from 'react';
import { Link } from '@inertiajs/react';
import { ArrowUpRight, ChevronLeft, ChevronRight, Search, Inbox, X, Plus } from 'lucide-react';
export const money = value => new Intl.NumberFormat('en-US', {style:'currency', currency:'USD', maximumFractionDigits:2}).format((Number(value)||0)/100);
export function submissionKey(){
 if(typeof globalThis.crypto?.randomUUID==='function')return globalThis.crypto.randomUUID();
 const bytes=new Uint8Array(16);
 if(typeof globalThis.crypto?.getRandomValues==='function')globalThis.crypto.getRandomValues(bytes);else bytes.forEach((_,index)=>{bytes[index]=Math.floor(Math.random()*256)});
 bytes[6]=(bytes[6]&0x0f)|0x40;bytes[8]=(bytes[8]&0x3f)|0x80;
 const hex=Array.from(bytes,value=>value.toString(16).padStart(2,'0')).join('');
 return `${hex.slice(0,8)}-${hex.slice(8,12)}-${hex.slice(12,16)}-${hex.slice(16,20)}-${hex.slice(20)}`;
}
export const shortMoney = value => '$' + new Intl.NumberFormat('en-US', {notation:'compact', maximumFractionDigits:1}).format((Number(value)||0)/100);
export const dateLabel = value => value ? new Date(value.slice(0,10)+'T12:00:00').toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}) : '\u2014';
export const code = (type,id) => ({customers:'CUS',invoices:'INV',receipts:'RCV',employees:'EMP',payrolls:'PAY',expenses:'EXP','gate-passes':'GP',transactions:'TXN','fixed-expenses':'FIX',accounts:'ACC'}[type] || 'REF')+'-'+String(id).padStart(4,'0');
export const sum = (rows,key) => rows.reduce((s,r)=>s+Number(r[key]||0),0);
export function Ref({type,id,children}) { return id ? <Link className="record-link" href={`/${type}/${id}`}>{children || code(type,id)}</Link> : <span>\u2014</span>; }
export function Badge({children}) { return <span className={`badge ${['Paid','Active','Present','Posted'].includes(children)?'solid': ['Voided','Inactive','Absent','Unpaid'].includes(children)?'muted':''}`}><i/>{children}</span>; }
export function Avatar({name='',size=''}) { return <span className={`avatar ${size}`}>{name.split(' ').map(n=>n[0]).slice(0,2).join('')}</span>; }
export function Empty({title='No records yet',text='Your records will appear here once you add the first one.'}) { return <div className="empty"><Inbox size={30}/><h3>{title}</h3><p>{text}</p></div>; }
export function Card({title,subtitle,action,children,className=''}) { return <section className={`card ${className}`}><div className="card-heading"><div><h3>{title}</h3>{subtitle&&<p>{subtitle}</p>}</div>{action}</div>{children}</section>; }
export function Stat({label,value,note,icon:Icon,accent=false}) { return <div className={`stat-card ${accent?'accent':''}`}><div className="stat-top"><span>{label}</span>{Icon&&<span className="stat-icon"><Icon size={18}/></span>}</div><strong>{value}</strong><div className="stat-note"><span className="tiny-dot"/>{note}</div></div>; }
export function Table({rows,columns,searchable=true,searchPlaceholder='Search records\u2026',pageSize=8,emptyText,toolbar}) {
 const [search,setSearch]=useState(''); const [page,setPage]=useState(1);
 const filtered=rows.filter(r=>Object.values(r).join(' ').toLowerCase().includes(search.toLowerCase()));
 const pages=Math.max(1,Math.ceil(filtered.length/pageSize)); const current=Math.min(page,pages);
 return <div className="table-card">{(searchable||toolbar)&&<div className="table-toolbar">{searchable&&<div className="input-search"><Search size={16}/><input aria-label={searchPlaceholder} placeholder={searchPlaceholder} value={search} onChange={e=>{setSearch(e.target.value);setPage(1)}}/></div>}{toolbar}</div>}<div className="table-scroll"><table><thead><tr>{columns.map(c=><th key={c.key} className={c.right?'align-right':''}>{c.label}</th>)}</tr></thead><tbody>{filtered.slice((current-1)*pageSize,current*pageSize).map((row,i)=><tr key={row.id??i}>{columns.map(c=><td key={c.key} className={c.right?'align-right':''}>{c.render?c.render(row):row[c.key]??'\u2014'}</td>)}</tr>)}</tbody></table>{!filtered.length&&<Empty text={emptyText}/>}</div><div className="table-footer"><span>Showing {filtered.length ? (current-1)*pageSize+1:0}?{Math.min(current*pageSize,filtered.length)} of {filtered.length} records</span><div className="pagination"><button aria-label="Previous page" disabled={current<=1} onClick={()=>setPage(current-1)}><ChevronLeft size={15}/></button><span>{current} / {pages}</span><button aria-label="Next page" disabled={current>=pages} onClick={()=>setPage(current+1)}><ChevronRight size={15}/></button></div></div></div>;
}
export function Field({label,error,children,wide=false}) { return <label className={`field ${wide?'wide':''}`}><span>{label}</span>{children}{error&&<small className="field-error">{error}</small>}</label>; }
export function Modal({title,subtitle,children,onClose,wide=false}) {
 React.useEffect(()=>{const old=document.body.style.overflow;document.body.style.overflow='hidden';const key=e=>{if(e.key==='Escape')onClose()};document.addEventListener('keydown',key);const first=document.querySelector('.modal input,.modal select');first?.focus();return()=>{document.body.style.overflow=old;document.removeEventListener('keydown',key)}},[]);
 const trap=e=>{if(e.key!=='Tab')return;const els=[...e.currentTarget.querySelectorAll('button,input,select,textarea,a[href]')].filter(el=>!el.disabled);if(e.shiftKey&&document.activeElement===els[0]){e.preventDefault();els.at(-1)?.focus()}else if(!e.shiftKey&&document.activeElement===els.at(-1)){e.preventDefault();els[0]?.focus()}};
 return <div className="modal-backdrop" onMouseDown={e=>{if(e.target===e.currentTarget)onClose()}}><section className={`modal ${wide?'modal-wide':''}`} role="dialog" aria-modal="true" aria-label={title} onKeyDown={trap}><div className="modal-heading"><div><span className="eyebrow">THREADLINE WORKSPACE</span><h2>{title}</h2><p>{subtitle||'Keep every detail connected to your business.'}</p></div><button className="icon-button" onClick={onClose} aria-label="Close dialog"><X size={20}/></button></div>{children}</section></div>;
}
export function exportCSV(name,rows) {
 if(!rows.length)return;
 const keys=Object.keys(rows[0]); const safe=v=>{let s=String(v??'');if(/^[=+\-@\t\r]/.test(s))s="'"+s;return '"'+s.replaceAll('"','""')+'"'};
 const text=[keys,...rows.map(r=>keys.map(k=>r[k]))].map(row=>row.map(safe).join(',')).join('\r\n');
 const url=URL.createObjectURL(new Blob(['\ufeff'+text],{type:'text/csv;charset=utf-8;'}));const a=document.createElement('a');a.href=url;a.download=name+'.csv';a.click();URL.revokeObjectURL(url);
}
