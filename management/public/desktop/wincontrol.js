window.createNurDesktopPanel = (root, desktop, windowModel) => {
    'use strict';
    let state = desktop.state;
    const options = desktop.options;
    const $ = id => root.querySelector(`[data-control="${id}"]`) || document.getElementById(id);
    const raw = () => state.observed?.raw || {};
    const observed = () => state.observed?.normalized || {};
    const value = x => x === null || x === undefined || x === '' ? '—' : typeof x === 'object' ? JSON.stringify(x) : String(x);
    const bytes = n => { if (!Number.isFinite(Number(n))) return '—'; n=Number(n); const units=['B','KiB','MiB','GiB','TiB'];let i=0;while(n>=1024&&i<4){n/=1024;i++;}return `${n.toFixed(i ? 1 : 0)} ${units[i]}`; };
    const duration = n => { if(!Number.isFinite(Number(n))) return '—'; n=Math.floor(Number(n));return `${Math.floor(n/86400)}d ${String(Math.floor(n%86400/3600)).padStart(2,'0')}:${String(Math.floor(n%3600/60)).padStart(2,'0')}:${String(n%60).padStart(2,'0')}`; };
    const c = (label,key,width) => ({label,key,width});
    const definitions = {
        arp: {title:'ARP',icon:'arp',columns:[c('','flags','116px'),c('IP Address','ip','190px'),c('MAC Address','mac','222px'),c('Interface','interface','175px'),c('VRF','vrf','160px'),c('Bridge Port','bridge','143px'),c('Host Name','hostname','206px'),c('Status','status','176px'),c('','_space','auto')],rows:()=>{
            const leases=observed().dhcp_leases||[];
            return (raw().neighbors||[]).map(n=>{const s=(Array.isArray(n.state)?n.state:(n.state?[n.state]:[])).join(', ').toLowerCase();return {flags:s==='permanent'?'S':s?'D':'',ip:n.dst,mac:n.lladdr?.toUpperCase()||'',interface:n.dev,vrf:n.vrf||n.table||'',bridge:n.bridge_port||'',hostname:leases.find(l=>l.ip===n.dst&&l.mac?.toLowerCase()===n.lladdr?.toLowerCase()&&Number(l.expiry)>Date.now()/1000)?.hostname?.replace(/^\*$/,'')||'',status:s};});
        },empty:'No IPv4 neighbors are currently observed. Refresh after traffic reaches another device.'},
        interfaces: {title:'Interface List',icon:'interfaces',editable:true,columns:[c('','flags','4%'),c('Name','name','20%'),c('Type','type','16%'),c('MTU','mtu','9%'),c('MAC Address','mac','20%'),c('State','status','12%'),c('Master','master','19%')],rows:()=> (observed().interfaces||[]).map(i=>({...i,flags:i.state==='UP'?'R':'',mac:raw().links?.find(l=>l.ifname===i.name)?.address?.toUpperCase(),status:i.state})),empty:'No interfaces observed.'},
        addresses: {title:'Address List',icon:'network',editable:true,columns:[c('','flags','4%'),c('Address','cidr','30%'),c('Network','network','25%'),c('Interface','interface','25%'),c('Scope','scope','16%')],rows:()=> (observed().addresses||[]).filter(i=>i.family==='inet').map(i=>({...i,flags:raw().addresses?.find(l=>l.ifname===i.interface)?.addr_info?.find(a=>a.local===i.local)?.dynamic?'D':'',network:network(i.local,i.prefixlen)}))},
        routes: {title:'Route List',icon:'route',editable:true,columns:[c('','flags','4%'),c('Dst. Address','destination','25%'),c('Gateway','gateway','22%'),c('Interface','device','20%'),c('Protocol','protocol','15%'),c('Scope','scope','14%')],rows:()=> (observed().routes||[]).map(r=>({...r,flags:Array.isArray(r.flags)?r.flags.join(', '):'',destination:r.destination==='default'?'0.0.0.0/0':r.destination}))},
        dhcp: {title:'DHCP Server — Leases',icon:'list',editable:true,columns:[c('','flags','4%'),c('Address','ip','24%'),c('MAC Address','mac','26%'),c('Host Name','hostname','24%'),c('Expires In','expires','22%')],rows:()=> (observed().dhcp_leases||[]).map(l=>({...l,flags:'D',mac:l.mac?.toUpperCase(),hostname:l.hostname==='*'?'':l.hostname,expires:duration(Math.max(0,Number(l.expiry)-Date.now()/1000))})),empty:'No DHCP leases. Connect a client to the NurRouterLAN virtual network.'},
        dns: {title:'DNS Settings',icon:'network',editable:true,columns:[c('Property','property','40%'),c('Value','value','60%')],rows:()=>[
            {property:'Upstream servers',value:(state.applied?.configuration?.services?.dns?.forwarders||[]).join(', ')},
            {property:'Search domains',value:(state.applied?.configuration?.services?.dns?.search_domains||[]).join(', ')},
            {property:'DNS / DHCP process',value:observed().services?.dnsmasq_running?'Running':'Not running'},
        ]},
        firewall: {title:'Firewall',icon:'shield',editable:true,columns:[c('','flags','4%'),c('Chain','chain','15%'),c('Action','action','14%'),c('Description','comment','39%'),c('Packets','packets','14%'),c('Bytes','bytes','14%')],rows:()=> (raw().firewall?.nftables||[]).filter(x=>x.rule).map(x=>{const r=x.rule;const expr=r.expr||[];const counter=expr.find(e=>e.counter)?.counter;const target=expr.find(e=>e.accept!==undefined||e.drop!==undefined||e.reject!==undefined||e.jump||e.masquerade!==undefined||e.snat||e.dnat);return{flags:'',chain:r.chain,action:target?target.jump?`jump → ${target.jump.target}`:Object.keys(target)[0]:'—',comment:r.comment?.replace(/^!fw4: /,'')||'',packets:counter?.packets,bytes:counter?bytes(counter.bytes):''};}),empty:'No firewall rules observed.'},
        bridge: {title:'Bridge VLANs',icon:'network',editable:true,columns:[c('','flags','4%'),c('Interface','interface','30%'),c('VLAN ID','vid','22%'),c('Flags','vlanFlags','44%')],rows:()=> (observed().bridge_vlans||[]).flatMap(b=>(b.vlans||[]).map(v=>({flags:'D',interface:b.ifname,vid:v.vlan,vlanFlags:(v.flags||[]).join(', ')}))),empty:'No bridge VLANs are configured on this appliance.'},
        ipv6: {title:'IPv6 — Addresses',icon:'network',columns:[c('','flags','4%'),c('Address','cidr','52%'),c('Interface','interface','24%'),c('Scope','scope','20%')],rows:()=> (observed().addresses||[]).filter(a=>a.family==='inet6').map(a=>({...a,flags:'D'}))},
        traffic: {title:'Interface Traffic',icon:'chart',columns:[c('Name','name','22%'),c('State','operstate','14%'),c('RX Bytes','rx','19%'),c('TX Bytes','tx','19%'),c('RX Packets','rx_packets','13%'),c('TX Packets','tx_packets','13%')],rows:()=> (observed().traffic||[]).map(i=>({...i,rx:bytes(i.rx_bytes),tx:bytes(i.tx_bytes)}))},
        pppoe: {title:'PPP — PPPoE Sessions',icon:'network',editable:true,columns:[c('Name','name','20%'),c('Device','device','20%'),c('Address','address','25%'),c('Status','status','15%'),c('Uptime','uptime','20%')],rows:()=> (observed().pppoe_sessions||[]).map(p=>({...p,device:p.device||p.l3_device,status:p.up?'running':'down',address:p.address||p.ipv4_address,uptime:duration(p.uptime)})),empty:'No PPPoE client sessions. This lab uses a static NAT WAN.'},
        queues: {title:'Queues',icon:'chart',columns:[c('Interface','interface','25%'),c('Download','download','25%'),c('Upload','upload','25%'),c('Status','status','25%')],rows:()=> (state.applied?.configuration?.services?.pppoe_clients||[]).filter(p=>p.download_kbps||p.upload_kbps).map(p=>({interface:p.name,download:`${p.download_kbps||0} kbit/s`,upload:`${p.upload_kbps||0} kbit/s`,status:'configured'})),empty:'No bandwidth shaping profiles are configured.'},
        connections: {title:'Firewall — Connections',icon:'shield',columns:[c('Protocol','protocol','8%'),c('Source','source','20%'),c('Destination','destination','20%'),c('State','status','12%'),c('Timeout (s)','timeout','10%'),c('Packets (both ways)','packets','15%'),c('Bytes (both ways)','traffic','15%')],rows:()=> (raw().connections||[]).map(parseConnection),empty:'No tracked connections observed. This view requires a runtime with connection tracking support.'},
        systemlog: {title:'Log — Router Events',icon:'list',columns:[c('Time','time','24%'),c('Topics','topic','18%'),c('Message','message','58%')],rows:()=> (raw().system_logs||[]).map(line=>{const m=line.match(/^(.+?\d{4})\s+(\S+)\s+(.*)$/);return m?{time:m[1],topic:m[2],message:m[3]}:{time:'',topic:'',message:line};}),empty:'No router events available. Refresh to retrieve events; older runtimes require the system log update.'},
        audit: {title:'Log — Management Audit',icon:'list',columns:[c('Time','time','23%'),c('Topics','action','25%'),c('Message','summary','40%'),c('Status','status','12%')],rows:()=> (state.audit||[]).map(a=>({...a,time:new Date(a.created_at).toLocaleString()}))},
        revisions: {title:'Configuration Revisions',icon:'list',columns:[c('Revision','revision_number','12%'),c('Status','apply_status','18%'),c('Created','created','25%'),c('Applied','applied','25%'),c('Confirmed','confirmed','20%')],rows:()=> (state.revisions||[]).map(r=>({...r,created:new Date(r.created_at).toLocaleString(),applied:r.applied_at?new Date(r.applied_at).toLocaleString():'',confirmed:r.confirmed_at?'yes':'no'}))},
        overview: {title:'Quick Set',icon:'settings',special:true}, resources:{title:'System Resources',icon:'settings',special:true},
    };
    function parseConnection(line){
        const tokens=line.trim().split(/\s+/),fields={};
        for(const token of tokens){const i=token.indexOf('=');if(i>0){const key=token.slice(0,i);(fields[key]??=[]).push(token.slice(i+1));}}
        const endpoint=(ip,port)=>ip?(ip.includes(':')?'['+ip+']':ip)+(port?':'+port:''):'';
        const sum=key=>fields[key]?.reduce((n,v)=>n+Number(v),0);
        return {protocol:tokens[2],source:endpoint(fields.src?.[0],fields.sport?.[0]),destination:endpoint(fields.dst?.[0],fields.dport?.[0]),status:tokens[5]?.includes('=')?'tracked':tokens[5],timeout:tokens[4],packets:sum('packets')??'unavailable',traffic:fields.bytes?bytes(sum('bytes')):'unavailable'};
    }
    function network(ip,prefix){if(!ip)return '';const parts=ip.split('.').map(Number);let n=parts.reduce((v,p)=>(v*256+p)>>>0,0);n=(n&(prefix?(-1<<(32-prefix)):0))>>>0;return [24,16,8,0].map(b=>(n>>>b)&255).join('.')+`/${prefix}`;}
    const current=windowModel.panel;
    let sortKey='',sortDirection=1,selected=new Set(),lastRows=[],live=!!windowModel.live,lastSuccess=Date.parse(state.observed?.recorded_at||''),anchor=null,focusedRow=null;
    const node=(tag,text,className)=>{const e=document.createElement(tag);if(text!==undefined)e.textContent=text;if(className)e.className=className;return e;};
    const definition=()=>Object.prototype.hasOwnProperty.call(definitions,current)?definitions[current]:{title:current.startsWith('unsupported:')?current.slice(12):current,icon:'settings',special:true,unsupported:true};
    const openPanel=panel=>desktop.open(panel);
    function render(rebuildFilters=false){
        const d=definition();if(current==='arp')d.editable=!!state.runtime_capabilities?.neighbor_management;root.dataset.panel=current;$('panel-title').textContent=d.title;$('panel-icon').querySelector('use').setAttribute('href',`#i-${d.icon}`);
        renderFirewallTabs();renderBridgeTabs();renderWireGuardTabs();renderIpv6Tabs();
        root.setAttribute('aria-label',d.title);
        $('columns-open').disabled=!!d.special;$('filter-toggle').disabled=!!d.special;$('table-search').disabled=!!d.special;
        const add=$('new-entry');add.setAttribute('aria-disabled',d.editable?'false':'true');add.disabled=!d.editable;add.title=d.editable?'Open staged configuration editor':current==='arp'?'Static ARP editing is not exposed by this appliance API':'This view is read-only';
        add.title=d.editable?'Stage a new entry':'Unavailable: verified appliance capability required';
        $('data-grid').hidden=!!d.special;$('special-panel').hidden=!d.special;
        if(d.special){renderSpecial(d);lastRows=[];updateEntryActions();$('row-count').textContent='';}else renderTable(d,rebuildFilters);
        renderStatus();
    }
    function visibleColumns(d){return desktop.columns(current,d.columns);}
    function renderTable(d,rebuildFilters,preserveFocus=true){
        const grid=$('data-grid'),position={top:grid.scrollTop,left:grid.scrollLeft};
        const focus=document.activeElement,hadFocus=root.contains(focus),focusKey=focus?.dataset.rowKey,focusColumn=focus?.closest('[data-column-key]')?.dataset.columnKey,focusSeparator=focus?.classList.contains('column-resizer');
        const columns=visibleColumns(d);
        let rows=(d.rows?.()||[]).map((r,index)=>({...r,_index:index}));
        if(rebuildFilters){const old=$('status-filter').value;const statuses=[...new Set(rows.map(r=>r.status||r.state||r.operstate).filter(Boolean))].sort();$('status-filter').replaceChildren(node('option','All statuses'));$('status-filter').firstChild.value='';statuses.forEach(s=>{const o=node('option',String(s));o.value=s;$('status-filter').append(o);});if(statuses.includes(old))$('status-filter').value=old;}
        const query=$('table-search').value.trim().toLowerCase(),status=$('status-filter').value;
        const total=rows.length;rows=rows.filter(r=>(!query||columns.some(col=>value(r[col.key]).toLowerCase().includes(query)))&&(!status||(r.status||r.state||r.operstate)===status));
        if(sortKey)rows.sort((a,b)=>{const x=value(a[sortKey]),y=value(b[sortKey]),ip=v=>/^([0-9]{1,3}\.){3}[0-9]{1,3}$/.test(v)?v.split('.').reduce((n,o)=>n*256+Number(o),0):null;const ix=ip(x),iy=ip(y);return(ix!==null&&iy!==null?ix-iy:x.localeCompare(y,undefined,{numeric:true}))*sortDirection;});
        lastRows=rows;
        selected=new Set([...selected].filter(index=>rows.some(r=>r._index===index)));
        const table=$('router-table'),thead=table.querySelector('thead'),tbody=table.querySelector('tbody');
        table.style.minWidth=current==='arp'?`${columns.reduce((n,c)=>n+(c.key==='_space'?0:parseFloat(c.width)||100),0)}px`:'';
        const tr=node('tr');columns.forEach(col=>{
            const th=node('th');th.scope='col';th.dataset.columnKey=col.key;if(col.width)th.style.width=col.width;
            th.tabIndex=col.key==='_space'?-1:0;th.setAttribute('aria-sort',sortKey===col.key?(sortDirection===1?'ascending':'descending'):'none');
            const label=node('span',col.label);th.append(label);if(sortKey===col.key)th.append(node('span',sortDirection===1?'⌃':'⌄','sort-indicator'));
            const action=()=>{sortDirection=sortKey===col.key?-sortDirection:1;sortKey=col.key;renderTable(d);};
            th.onclick=e=>{if(!e.target.closest('.column-resizer')&&col.key!=='_space')action();};
            th.onkeydown=e=>{if((e.key==='Enter'||e.key===' ')&&e.target===th&&col.key!=='_space'){e.preventDefault();action();}};
            if(col.key!=='_space')desktop.installColumn(root,th,current,col,()=>renderTable(d));
            tr.append(th);
        });thead.replaceChildren(tr);
        tbody.replaceChildren(...rows.map((r,position)=>{
            const row=node('tr');row.dataset.rowKey=rowIdentity(r);row.tabIndex=focusedRow===row.dataset.rowKey||(!focusedRow&&position===0)?0:-1;
            row.classList.toggle('selected',selected.has(r._index));row.setAttribute('aria-selected',String(selected.has(r._index)));
            columns.forEach(col=>{const td=node('td');if(col.key==='flags'){td.className='flags';td.append(node('span',r.flags||''));}else td.textContent=col.key==='_space'?'':value(r[col.key]);td.title=value(r[col.key]);row.append(td);});
            const choose=e=>{if(e.shiftKey&&anchor!==null){const start=rows.findIndex(x=>x._index===anchor),end=rows.findIndex(x=>x._index===r._index);if(!e.ctrlKey&&!e.metaKey)selected.clear();rows.slice(Math.max(0,Math.min(start,end)),Math.max(start,end)+1).forEach(x=>selected.add(x._index));}else if(e.ctrlKey||e.metaKey){selected.has(r._index)?selected.delete(r._index):selected.add(r._index);anchor=r._index;}else{selected.clear();selected.add(r._index);anchor=r._index;}focusedRow=rowIdentity(r);renderTable(d,false,false);findRow(focusedRow)?.focus({preventScroll:true});};
            row.onclick=choose;row.ondblclick=()=>showDetails(r,d);row.onfocus=()=>{focusedRow=row.dataset.rowKey;};
            row.onkeydown=e=>{
                if(['ArrowDown','ArrowUp','Home','End','PageDown','PageUp'].includes(e.key)){
                    e.preventDefault();let i=rows.indexOf(r),step=Math.max(1,Math.floor(grid.clientHeight/24)-1);i=e.key==='Home'?0:e.key==='End'?rows.length-1:i+({ArrowDown:1,ArrowUp:-1,PageDown:step,PageUp:-step}[e.key]);i=Math.max(0,Math.min(rows.length-1,i));
                    const next=rows[i];if(!next)return;if(!e.ctrlKey&&!e.metaKey){if(!e.shiftKey)selected.clear();if(e.shiftKey&&anchor!==null){const start=rows.findIndex(x=>x._index===anchor);rows.slice(Math.min(start,i),Math.max(start,i)+1).forEach(x=>selected.add(x._index));}else{selected.add(next._index);anchor=next._index;}}
                    focusedRow=rowIdentity(next);renderTable(d,false,false);const target=findRow(focusedRow);target?.focus({preventScroll:true});target?.scrollIntoView({block:'nearest',inline:'nearest'});
                }else if(e.key==='Enter'){e.preventDefault();showDetails(r,d);}else if(e.key===' '){e.preventDefault();choose(e);}else if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='a'){e.preventDefault();rows.forEach(x=>selected.add(x._index));renderTable(d);findRow(rowIdentity(r))?.focus({preventScroll:true});}else if(e.key==='Delete'&&!$('entry-remove').disabled){e.preventDefault();$('entry-remove').click();}
            };return row;
        }));
        if(current==='arp'&&state.runtime_capabilities?.neighbor_observation&&!state.runtime_capabilities?.neighbor_management){$('state-notice').textContent='ARP editing is locked until verified add/edit/delete, enable/disable, comments and reboot persistence pass for this appliance image.';$('state-notice').hidden=false;}
        if(current==='arp'&&!state.runtime_capabilities?.neighbor_observation){$('state-notice').textContent='Neighbor telemetry and verified ARP management are unavailable on this runtime. Managed entries are shown as configuration only.';$('state-notice').hidden=false;}
        $('empty-state').hidden=rows.length>0;$('empty-state').textContent=total===0?(d.empty||'No entries observed.'):'No entries match this filter.';
        $('row-count').textContent=rows.length===total?String(total):`${rows.length} / ${total}`;$('selected-count').textContent=selected.size?`${selected.size} selected`:'';updateEntryActions();
        grid.scrollTop=position.top;grid.scrollLeft=position.left;
        if(preserveFocus&&hadFocus&&focusKey)findRow(focusKey)?.focus({preventScroll:true});
        else if(preserveFocus&&hadFocus&&focusColumn){const th=[...thead.querySelectorAll('[data-column-key]')].find(t=>t.dataset.columnKey===focusColumn);(focusSeparator?th?.querySelector('.column-resizer'):th)?.focus({preventScroll:true});}
    }
    const findRow=key=>[...$('router-table').querySelectorAll('tbody tr')].find(row=>row.dataset.rowKey===key);
    function openDialog(dialog){
        const active=document.activeElement,opener={row:active?.dataset.rowKey,control:active?.dataset.control};
        dialog.addEventListener('close',()=>{if(!root.isConnected||root.hidden)return;const target=opener.row?findRow(opener.row):opener.control?$(opener.control):null;(target||root.querySelector('.window-title'))?.focus({preventScroll:true});},{once:true});
        dialog.showModal();
    }
    function showDetails(r,d){if(openConfiguration(r,d))return;$('row-dialog-title').textContent=`${d.title} — ${r.ip||r.name||r.interface||'entry'}`;const dl=node('dl');d.columns.filter(c=>c.label).forEach(c=>{dl.append(node('dt',c.label),node('dd',value(r[c.key])));});$('row-dialog-body').replaceChildren(dl);openDialog($('row-dialog')); }
    function renderSpecial(d){if(current==='diagnostics')return renderDiagnostics();const area=$('special-panel');area.replaceChildren(node('h2',d.title));if(d.unsupported){area.append(node('p',`${d.title} management is not available in this NurRouterOS build.`));area.append(node('p','This menu is reserved for a future supported implementation.','muted'));return;}
        const sys=raw().system||{},mem=sys.memory||{},cards=[];
        if(current==='resources')cards.push(['Uptime',duration(sys.uptime)],['Architecture','x86_64'],['Total memory',bytes(mem.total)],['Free memory',bytes(mem.free)],['Available memory',bytes(mem.available)],['Load (1 minute)',sys.load? (sys.load[0]/65536).toFixed(2):'—'],['Disk available',sys.root?bytes(sys.root.avail*1024):'—'],['Observed at',state.observed?.recorded_at?new Date(state.observed.recorded_at).toLocaleString():'—']);
        else cards.push(['Identity',state.router.name],['Management address',state.router.management_cidr],['Configuration',state.router.status],['Applied revision',value(state.applied?.revision_id)],['Desired revision',value(state.desired?.revision_id)],['Safe Mode',state.safe_mode?.status||'ready'],['Network interfaces',String(observed().interfaces?.length||0)],['DHCP leases',String(observed().dhcp_leases?.length||0)]);
        const grid=node('div',undefined,'resource-grid');cards.forEach(([k,v])=>{const card=node('div',undefined,'resource-item');card.append(node('small',k),node('strong',value(v)));grid.append(card);});area.append(grid);
        if(current==='overview'){const links=node('div',undefined,'summary-line');[['Configure network',options.configureUrl],['Back up configuration',options.backupUrl],['PPPoE access server',options.brasUrl]].forEach(([t,url])=>{const a=node('a',t,'button');a.href=url;links.append(a);});area.append(links);area.append(node('p','Inspect live state, stage configuration changes, and apply them with a timed rollback.','muted'));}
    }
    function renderStatus(error){const age=Number.isFinite(lastSuccess)?Math.max(0,(Date.now()-lastSuccess)/1000):Infinity;const connection=error?'Disconnected':age>120?'Stale data':'Connected';$('connection-state').textContent=connection;$('connection-state').classList.toggle('offline',!!error);$('status-connection').textContent=`${state.router.status} · ${connection.toLowerCase()}`;$('observed-time').textContent=Number.isFinite(lastSuccess)?`Observed ${new Date(lastSuccess).toLocaleTimeString()}`:'Not observed yet';
        const sys=raw().system||{},mem=sys.memory||{};if(sys.uptime!==undefined)$('status-uptime').textContent=duration(sys.uptime+Math.max(0,Number.isFinite(lastSuccess)?(Date.now()-lastSuccess)/1000:0));if(mem.total)$('status-memory').textContent=`${bytes(mem.free)} / ${bytes(mem.total)}`;const mgmt=raw().links?.find(l=>l.ifname===state.router.management_interface);if(mgmt?.address)$('status-mac').textContent=mgmt.address.toUpperCase();updateApplyState();
    }
    const rowIdentity=r=>{
        if(r.ip)return `ip/${r.interface||r.mac||''}/${r.ip}`;
        if(r.cidr)return `address/${r.interface}/${r.cidr}`;
        if(r.name)return `name/${r.name}`;
        if(r.destination)return `route/${r.device||r.interface||''}/${r.destination}/${r.gateway||''}`;
        if(r._editIndex!==undefined)return `configuration/${r._editIndex}`;
        if(r.id||r.revision_number)return `record/${r.id||r.revision_number}`;
        return JSON.stringify(Object.fromEntries(Object.entries(r).filter(([k])=>!['_index','status','state','packets','bytes','traffic','rx','tx','rx_packets','tx_packets','expires','uptime','handshake','transfer'].includes(k))));
    };
    const refresh=()=>desktop.refresh();
    $('table-search').addEventListener('input',()=>{selected.clear();if(!definition().special)renderTable(definition());});$('status-filter').addEventListener('change',()=>renderTable(definition()));
    $('filter-toggle').onclick=()=>{$('filter-bar').hidden=!$('filter-bar').hidden;$('filter-toggle').setAttribute('aria-expanded',String(!$('filter-bar').hidden));};$('clear-filter').onclick=()=>{$('table-search').value='';$('status-filter').value='';render();};
    $('refresh-table').onclick=refresh;
    $('live-toggle').setAttribute('aria-pressed',String(live));$('live-toggle').textContent=live?'Ⅱ Live':'▷ Live';
    $('live-toggle').onclick=()=>{live=!live;windowModel.live=live;desktop.save();$('live-toggle').setAttribute('aria-pressed',String(live));$('live-toggle').textContent=live?'Ⅱ Live':'▷ Live';if(live)refresh();};
    $('apply-open').onclick=()=>openDialog($('apply-dialog'));
    $('window-minimize').onclick=()=>desktop.minimize(windowModel);
    $('window-maximize').onclick=()=>desktop.maximize(windowModel);
    $('window-close').onclick=()=>desktop.close(windowModel);
    $('columns-open').onclick=()=>desktop.editColumns(root,current,definition().columns,()=>renderTable(definition()));
    const schemas = {
        arp:{module:'arp',path:'static_neighbors',title:'ARP Entry',fields:[['ip','IP Address','text',true],['mac','MAC Address','text',true],['interface','Interface','interface',true],['disabled','Disabled','checkbox'],['comment','Comment','text']]},
        addresses:{module:'addresses',path:'addresses',title:'IP Address',fields:[['cidr','Address','text',true],['interface','Interface','interface',true]]},
        routes:{module:'routes',path:'routes',title:'Route',fields:[['destination','Dst. Address','text',true],['gateway','Gateway','text'],['device','Interface','interface',true],['metric','Distance / Metric','number']]},
        'dhcp-servers':{module:'dhcp',path:'services.dhcp_servers',title:'DHCP Server',fields:[['interface','Interface','interface',true],['start','Pool start','number',true],['limit','Pool size','number',true],['lease_time','Lease Time','text',true],['domain','Domain','text'],['dns_servers','DNS Servers','list']]},
        dns:{module:'dns',path:'services.dns',title:'DNS Settings',fields:[['forwarders','Servers','list'],['search_domains','Search Domains','list']]},
    };
    const firewallFields=[['chain','Chain','select:input,forward,output',true],['action','Action','select:drop,accept,reject',true],['protocol','Protocol','select:all,tcp,udp,icmp'],['source','Src. Address','text'],['source_port','Src. Port','text'],['destination','Dst. Address','text'],['destination_port','Dst. Port','text'],['in_interface','In. Interface','optional-interface'],['out_interface','Out. Interface','optional-interface'],['disabled','Disabled','checkbox'],['comment','Comment','text']];
    schemas.firewall={module:'filter',path:'firewall.rules',title:'Firewall Rule',fields:firewallFields};
    schemas.nat={module:'nat',path:'firewall.nat',title:'NAT Rule',fields:[['chain','Chain','select:postrouting,prerouting',true],['action','Action','select:masquerade,snat,dnat',true],...firewallFields.slice(2,9),['to_address','To Address','text'],['to_port','To Port','text'],['disabled','Disabled','checkbox'],['comment','Comment','text']]};
    definitions['firewall-live']={...definitions.firewall,title:'Firewall — Live Rules',editable:false};
    const configRows=path=>()=>{
        const desired=atPath(state.desired?.configuration,path)||[],applied=atPath(state.applied?.configuration,path)||[];
        return desired.map((e,i)=>({...e,position:i+1,flags:e.disabled?'X':'',status:JSON.stringify(e)===JSON.stringify(applied[i])?'applied':'staged',ports:e.to_port?`${e.destination_port||'any'} → ${e.to_port}`:e.destination_port||'',_editIndex:i,_entry:e}));
    };
    definitions.firewall={title:'Firewall — Filter Rules',icon:'shield',editable:true,columns:[c('#','position','4%'),c('','flags','3%'),c('Action','action','8%'),c('Chain','chain','9%'),c('Protocol','protocol','7%'),c('Src. Address','source','13%'),c('Dst. Address','destination','13%'),c('Dst. Port','destination_port','8%'),c('In. Interface','in_interface','9%'),c('Out. Interface','out_interface','9%'),c('Comment','comment','14%'),c('State','status','7%')],rows:configRows('firewall.rules'),empty:'No filter rules configured. Use New to stage a rule.'};
    definitions.nat={title:'Firewall — NAT',icon:'shield',editable:true,columns:[c('#','position','4%'),c('','flags','3%'),c('Action','action','10%'),c('Chain','chain','10%'),c('Protocol','protocol','7%'),c('Dst. Address','destination','12%'),c('Ports','ports','11%'),c('To Address','to_address','12%'),c('In. Interface','in_interface','9%'),c('Out. Interface','out_interface','9%'),c('Comment','comment','10%'),c('State','status','7%')],rows:configRows('firewall.nat'),empty:'No NAT rules configured.'};
    function renderFirewallTabs(){const bar=$('firewall-tabs');if(!bar)return;bar.hidden=!['firewall','nat','firewall-live','connections'].includes(current);bar.replaceChildren(...[['firewall','Filter Rules'],['nat','NAT'],['firewall-live','Live Rules'],['connections','Connections']].map(([panel,title])=>{const b=node('button',title,'module-tab'+(current===panel?' active':''));b.type='button';b.onclick=()=>openPanel(panel);return b;}));}
    const atPath=(object,path)=>path.split('.').reduce((v,k)=>v?.[k],object);
    const configured=path=>atPath(state.desired?.configuration||{},path)||[];
    schemas.interfaces={module:'devices',path:'interfaces',title:'Bridge / VLAN Interface',fields:[['name','Name','text',true],['type','Type','select:bridge,vlan',true],['parent','Parent Interface','optional-interface'],['vlan_id','VLAN ID','number'],['mtu','MTU','number'],['vlan_filtering','VLAN Filtering','checkbox']]};
    schemas.bridge=schemas.interfaces;
    schemas.ipv6={module:'ipv6_addresses',path:'services.ipv6.addresses',title:'IPv6 Address',fields:[['interface','Interface','interface',true],['cidr','IPv6 Address','text',true]]};
    schemas['ipv6-routes']={module:'ipv6_routes',path:'services.ipv6.routes',title:'IPv6 Route',fields:[['interface','Interface','interface',true],['destination','Destination Prefix','text',true],['gateway','IPv6 Gateway','text'],['metric','Metric','number']]};
    const liveIpv6Rows=definitions.ipv6.rows;
    definitions.ipv6.editable=true;
    definitions.ipv6.rows=()=>{const entries=configured('services.ipv6.addresses'),seen=new Set();const rows=liveIpv6Rows().map(row=>{const i=entries.findIndex(e=>e.interface===row.interface&&e.cidr===row.cidr);if(i<0)return row;seen.add(i);return {...row,flags:'S',_editIndex:i,_entry:entries[i]};});entries.forEach((e,i)=>{if(!seen.has(i))rows.push({...e,flags:'*',scope:'configured',_editIndex:i,_entry:e});});return rows;};
    definitions['ipv6-routes']={title:'IPv6 — Routes',icon:'route',editable:true,columns:[c('Destination','destination','35%'),c('Gateway','gateway','30%'),c('Interface','interface','20%'),c('Metric','metric','15%')],rows:configRows('services.ipv6.routes')};
    function renderIpv6Tabs(){const bar=$('ipv6-tabs');if(!bar)return;bar.hidden=!['ipv6','ipv6-routes'].includes(current);bar.replaceChildren(...[['ipv6','Addresses'],['ipv6-routes','Routes']].map(([panel,title])=>{const b=node('button',title,'module-tab'+(current===panel?' active':''));b.onclick=()=>openPanel(panel);return b;}));}
    schemas.wireguard={module:'wg_interfaces',path:'services.wireguard.interfaces',title:'WireGuard Interface',fields:[['name','Name','text',true],['listen_port','Listen Port','number',true],['mtu','MTU','number'],['addresses','IPv4 Addresses','list',true],['private_key','Private Key (blank generates or preserves)','password']]};
    schemas['wireguard-peers']={module:'wg_peers',path:'services.wireguard.peers',title:'WireGuard Peer',fields:[['interface','Interface','wg-interface',true],['public_key','Public Key','text',true],['allowed_ips','Allowed IPv4 Networks','list',true],['endpoint_host','Endpoint Host','text'],['endpoint_port','Endpoint Port','number'],['persistent_keepalive','Keepalive (seconds)','number']]};
    definitions.wireguard={title:'WireGuard — Interfaces',icon:'shield',editable:true,columns:[c('Name','name','12%'),c('Listen Port','listen_port','12%'),c('Addresses','addresses','22%'),c('Public Key','public_key','40%'),c('State','status','14%')],rows:()=>configRows('services.wireguard.interfaces')().map(e=>({...e,addresses:e.addresses.join(', '),status:raw().links?.find(i=>i.ifname===e.name)?.operstate||e.status})),empty:'No WireGuard interfaces configured.'};
    definitions['wireguard-peers']={title:'WireGuard — Peers',icon:'shield',editable:true,columns:[c('Interface','interface','12%'),c('Public Key','public_key','35%'),c('Allowed Networks','allowed_ips','23%'),c('Endpoint','endpoint_host','20%'),c('Keepalive','persistent_keepalive','10%'),c('Last Handshake','handshake','20%'),c('RX / TX','transfer','15%')],rows:()=>configRows('services.wireguard.peers')().map(e=>{const live=(raw().wireguard||[]).find(p=>p.interface===e.interface&&p.public_key===e.public_key);return {...e,allowed_ips:e.allowed_ips.join(', '),handshake:live?.latest_handshake?new Date(live.latest_handshake*1000).toLocaleString():'never',transfer:live?`${bytes(live.rx_bytes)} / ${bytes(live.tx_bytes)}`:'—'};}),empty:'No WireGuard peers configured.'};
    function renderWireGuardTabs(){const bar=$('wireguard-tabs');if(!bar)return;bar.hidden=!['wireguard','wireguard-peers'].includes(current);bar.replaceChildren(...[['wireguard','Interfaces'],['wireguard-peers','Peers']].map(([panel,title])=>{const b=node('button',title,'module-tab'+(current===panel?' active':''));b.onclick=()=>openPanel(panel);return b;}));}
    schemas.pppoe={module:'pppoe',path:'services.pppoe_clients',title:'PPPoE Client',fields:[['name','Name','text',true],['interface','Interface','interface',true],['username','Username','text',true],['password_action','Credential operation','select:retain,replace,clear'],['password','Replacement password','password'],['service_name','Service Name','text'],['default_route','Default Route','checkbox'],['mtu','MTU','number'],['download_kbps','Download (kbit/s)','number'],['upload_kbps','Upload (kbit/s)','number']]};
    schemas.queues=schemas.pppoe;
    definitions.pppoe={title:'PPP — PPPoE Clients',icon:'ppp',editable:true,columns:[c('Name','name','15%'),c('Interface','interface','15%'),c('Username','username','20%'),c('Service','service_name','15%'),c('MTU','mtu','10%'),c('Default Route','default_route','10%'),c('State','status','15%')],rows:()=>configRows('services.pppoe_clients')().map(e=>({...e,status:(observed().pppoe_sessions||[]).find(p=>p.name===e.name)?.up?'running':e.status==='staged'?'staged':'not connected'})),empty:'No PPPoE clients configured. Use New to create one.'};
    definitions.queues={title:'Queues — PPPoE Rate Limits',icon:'chart',editable:true,columns:[c('Name','name','25%'),c('Interface','interface','25%'),c('Download (kbit/s)','download_kbps','25%'),c('Upload (kbit/s)','upload_kbps','25%')],rows:configRows('services.pppoe_clients'),empty:'Create a PPPoE client to configure its rate limits.'};
    schemas['bridge-ports']={module:'ports',path:'interfaces',title:'Bridge Port',fields:[['name','Interface','interface',true],['master','Bridge','bridge-interface',true]]};
    schemas['bridge-vlans']={module:'bridge_vlans',path:'bridge_vlans',title:'Bridge VLAN Membership',fields:[['device','Bridge','bridge-interface',true],['vid','VLAN ID','number',true],['port','Port','optional-interface'],['pvid','PVID','checkbox'],['untagged','Untagged','checkbox'],['self','Local (CPU)','checkbox']]};
    definitions['bridge-ports']={title:'Bridge — Ports',icon:'network',editable:true,columns:[c('Interface','name','30%'),c('Bridge','master','30%'),c('Type','type','20%'),c('State','status','20%')],rows:()=>configured('interfaces').map((e,i)=>({...e,_editIndex:i,_entry:e,status:JSON.stringify(e)===JSON.stringify((state.applied?.configuration?.interfaces||[])[i])?'applied':'staged'})).filter(e=>e.master),empty:'No bridge ports. Assign an unaddressed leaf interface using New.'};
    definitions['bridge-vlans']={title:'Bridge — VLANs',icon:'network',editable:true,columns:[c('Bridge','device','20%'),c('VLAN ID','vid','15%'),c('Port','port','25%'),c('PVID','pvid','13%'),c('Untagged','untagged','13%'),c('Local (CPU)','self','14%')],rows:configRows('bridge_vlans'),empty:'No bridge VLAN memberships configured.'};
    function renderBridgeTabs(){const bar=$('bridge-tabs');if(!bar)return;bar.hidden=!['bridge','bridge-ports','bridge-vlans'].includes(current);bar.replaceChildren(...[['bridge','Interfaces'],['bridge-ports','Ports'],['bridge-vlans','VLANs']].map(([panel,title])=>{const b=node('button',title,'module-tab'+(current===panel?' active':''));b.type='button';b.onclick=()=>openPanel(panel);return b;}));}
    const observedInterfaceRows=definitions.interfaces.rows;
    const deviceRows=()=>{
        const entries=configured('interfaces'),seen=new Set();
        const rows=observedInterfaceRows().map(row=>{const index=entries.findIndex(e=>e.name===row.name);if(index<0)return row;seen.add(index);const e=entries[index];return {...row,...e,status:row.status,_editIndex:['bridge','vlan'].includes(e.type)?index:undefined,_entry:e};});
        entries.forEach((e,i)=>{if(!seen.has(i))rows.push({...e,status:'configured',flags:'*',_editIndex:['bridge','vlan'].includes(e.type)?i:undefined,_entry:e});});return rows;
    };
    definitions.interfaces.rows=deviceRows;
    definitions.bridge={title:'Bridge / VLAN Interfaces',icon:'bridge',editable:true,columns:[c('Name','name','25%'),c('Type','type','15%'),c('Parent','parent','20%'),c('VLAN ID','vlan_id','12%'),c('MTU','mtu','12%'),c('State','status','16%')],rows:()=>deviceRows().filter(r=>['bridge','vlan'].includes(r.type)),empty:'No bridge or VLAN devices configured. Use New to create one.'};
    schemas.dhcp={module:'reservations',path:'services.dhcp_reservations',title:'DHCP Reservation',fields:[['interface','Interface','interface',true],['ip','IP Address','text',true],['mac','MAC Address','text',true],['hostname','Host Name','text'],['lease_time','Lease Time','text',true],['disabled','Disabled','checkbox'],['comment','Comment','text']]};
    definitions.dhcp.editable=true;
    const dynamicLeaseRows=definitions.dhcp.rows;
    definitions.dhcp.rows=()=>{
        const entries=configured('services.dhcp_reservations'),seen=new Set();
        const rows=dynamicLeaseRows().map(row=>{const i=entries.findIndex(e=>e.mac.toLowerCase()===row.mac.toLowerCase()&&e.ip===row.ip);if(i<0)return row;seen.add(i);return {...row,flags:entries[i].disabled?'X':'S',hostname:entries[i].hostname||row.hostname,_editIndex:i,_entry:entries[i]};});
        entries.forEach((e,i)=>{if(!seen.has(i))rows.push({...e,flags:e.disabled?'X':'S',expires:e.disabled?'disabled':'reserved',_editIndex:i,_entry:e});});return rows;
    };
    definitions.capabilities={title:'System — Feature Status',icon:'list',columns:[c('Feature','feature','25%'),c('Available controls','controls','43%'),c('Status / limitation','status','32%')],rows:()=>[
        {feature:'IPv4 addresses / Routes',controls:'Staged add, edit and remove',status:'Desktop editors'},
        {feature:'ARP neighbors',controls:state.runtime_capabilities?.neighbor_management?'Observe; managed static add/edit/delete, enable/disable, comments':state.runtime_capabilities?.neighbor_observation?'Observation only; editing locked':'Unavailable on this runtime',status:state.runtime_capabilities?.neighbor_management?'Qualified on this pinned appliance image':state.runtime_capabilities?.neighbor_observation?'Required operation and reboot proof has not passed':'Neighbor API not supported'},
        {feature:'DHCP / DNS',controls:'Server pools and upstream DNS editors; live leases',status:'Server, reservation and DNS editors'},
        {feature:'Firewall / NAT',controls:'Rules, ports, addresses, enable / disable, order, comments',status:'Live connection table; advanced matchers pending'},
        {feature:'Bridge / VLAN',controls:'Devices, port membership, tagged / untagged VLANs, PVID',status:'Physical port settings remain read-only'},
        {feature:'PPPoE client',controls:'Client credentials, service, MTU, default route',status:'Requires a reachable PPPoE access server'},
        {feature:'WireGuard',controls:'Interfaces, generated private keys, peers, IPv4 allowed networks',status:'Default-deny VPN zone; explicit firewall policy required'},
        {feature:'Queues',controls:'PPPoE download / upload rates',status:'Shaping entitlement required; other queue types pending'},
        {feature:'PPPoE server / RADIUS',controls:'Existing BRAS configuration page',status:'Experimental; not production validated'},
        {feature:'Backups / Revisions',controls:'Export, restore preview, revisions and Safe Mode',status:'Existing management pages'},
        {feature:'IPv6',controls:'Static IPv6 addresses and routes',status:'RA, DHCPv6 and IPv6 firewall-rule editors pending'},
        {feature:'WiFi / MPLS / Mesh / Dot1X',controls:'No configuration controls',status:'Backend management modules not implemented'},
        {feature:'Terminal / Switch',controls:'No configuration controls',status:'Backend management modules not implemented'},
        {feature:'Diagnostics',controls:'Unavailable on this appliance transport',status:'No enabled placeholder commands'},
        {feature:'Resources / Traffic / Router Events / Audit',controls:'Live observations and management audit',status:'Read-only views'},
    ]};
    definitions.diagnostics={title:'Tools — Diagnostics',icon:'chart',special:true};
    let diagnosticBusy=false,lastDiagnostic=null;
    function renderDiagnostics(){
        const area=$('special-panel');area.replaceChildren();
        const tabs=node('div',undefined,'module-tabs');
        const traffic=node('button','Interface Traffic','module-tab');traffic.onclick=()=>openPanel('traffic');tabs.append(traffic);area.append(tabs);
        area.append(node('p','Ping, traceroute and DNS lookup commands are unavailable on this appliance transport.','state-notice'));
        area.append(node('p','Interface Traffic is an implemented read-only observation view.','muted'));
    }
    const oldArpRows=definitions.arp.rows;
    definitions.arp.editable=true;
    definitions.arp.rows=()=>{
        const rows=oldArpRows(),entries=configured('static_neighbors');
        const used=new Set();
        const persisted=raw().managed_neighbors||[];
        const same=(a,b)=>a&&b&&['ip','mac','interface','disabled','comment'].every(k=>k==='disabled'?!!a[k]===!!b[k]:k==='mac'?String(a[k]||'').toLowerCase()===String(b[k]||'').toLowerCase():String(a[k]||'')===String(b[k]||''));
        const result=rows.map(row=>{
            const index=entries.findIndex(e=>e.ip===row.ip&&e.interface===row.interface);if(index<0)return row;
            used.add(index);const e=entries[index],applied=persisted.find(a=>a.ip===e.ip&&a.interface===e.interface);
            const pending=!same(e,applied);
            return {...row,flags:pending?row.flags:e.disabled?'X':row.flags,status:pending?`${row.status} (staged change)`:row.status,
                comment:e.comment||'',_editIndex:index,_entry:e};
        });
        entries.forEach((e,index)=>{if(!used.has(index)){
            const applied=persisted.find(a=>a.ip===e.ip&&a.interface===e.interface),pending=!same(e,applied);
            result.push({ip:e.ip,mac:e.mac?.toUpperCase(),interface:e.interface,vrf:'',flags:e.disabled?'X':'',
                status:pending?'staged':e.disabled?'disabled (no static pin)':'missing static pin',comment:e.comment||'',_editIndex:index,_entry:e});
        }});return result;
    };
    for(const panel of ['addresses','routes']){
        const before=definitions[panel].rows;
        definitions[panel].rows=()=>{
            const entries=configured(schemas[panel].path),used=new Set();
            const rows=before().map(row=>{const index=entries.findIndex(e=>panel==='addresses'?e.interface===row.interface&&e.cidr===row.cidr:(e.destination==='default'||e.destination==='0.0.0.0/0'?row.destination==='0.0.0.0/0':e.destination===row.destination)&&(e.gateway||'')===(row.gateway||'')&&e.device===row.device);if(index<0)return row;used.add(index);return{...row,_editIndex:index,_entry:entries[index]};});
            entries.forEach((e,i)=>{if(used.has(i))return;rows.push(panel==='addresses'?{...e,flags:'*',network:network(e.cidr.split('/')[0],Number(e.cidr.split('/')[1])),scope:'staged',_editIndex:i,_entry:e}:{...e,flags:'*',protocol:'staged',scope:'',_editIndex:i,_entry:e});});return rows;
        };
    }
    definitions['dhcp-servers']={title:'DHCP Server',icon:'network',editable:true,columns:[c('Name / Interface','interface','20%'),c('Pool Start','start','13%'),c('Pool Size','limit','12%'),c('Lease Time','lease_time','13%'),c('Domain','domain','20%'),c('DNS Servers','dns','22%')],rows:()=>configured('services.dhcp_servers').map((e,i)=>({...e,dns:(e.dns_servers||[]).join(', '),_editIndex:i,_entry:e})),empty:'No DHCP servers configured. Use New to stage one.'};
    function updateApplyState(){
        const staged=state.desired?.revision_id!==state.applied?.revision_id;
        if($('apply-revision-button'))$('apply-revision-button').disabled=!staged||!!state.safe_mode?.pending_operation_uuid;
        if($('apply-lock-version'))$('apply-lock-version').value=state.router.configuration_lock_version;
        if($('apply-revisions'))$('apply-revisions').textContent=`Desired revision ${state.desired?.revision_id??'none'} · Applied revision ${state.applied?.revision_id??'none'}`;
        if($('apply-current-note'))$('apply-current-note').hidden=staged;
        $('apply-open').textContent=staged?'Apply changes…':'Apply…';
    }
    function chosen(){return lastRows.find(row=>selected.has(row._index));}
    function updateEntryActions(){
        const row=selected.size===1?chosen():null,allowed=!!schemas[current]&&row?._editIndex!==undefined&&(current!=='arp'||!!state.runtime_capabilities?.neighbor_management);
        for(const [action,offset] of [['up',-1],['down',1]]){
            const button=$(`entry-move-${action}`),reorder=['firewall','nat'].includes(current);
            button.hidden=!reorder;button.style.display=reorder?'':'none';button.disabled=!(reorder&&allowed&&row._editIndex+offset>=0&&row._editIndex+offset<configured(schemas[current].path).length);
        }
        for(const name of ['enable','disable','remove','comment']){
            const button=$(`entry-${name}`);if(!button)continue;
            button.disabled=!(allowed&&(name==='remove'||['arp','firewall','nat','dhcp'].includes(current)));
            if(['arp','firewall','nat','dhcp'].includes(current)&&allowed&&name==='enable')button.disabled=!row._entry.disabled;
            if(['arp','firewall','nat','dhcp'].includes(current)&&allowed&&name==='disable')button.disabled=!!row._entry.disabled;
        }
    }
    async function mutate(payload,expected){
        const response=await fetch(options.mutationUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json',Accept:'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},body:JSON.stringify({...payload,expected_revision_id:expected?(expected.revision??null):(state.desired?.revision_id??null),expected_lock_version:expected?.lock??state.router.configuration_lock_version})});
        const result=await response.json();if(!response.ok){const message=result.errors?Object.values(result.errors).flat().join('\n'):result.message||'The entry could not be saved.';throw new Error(message);}
        selected.clear();desktop.setState(result.state);$('state-notice').textContent=result.message;$('state-notice').hidden=false;return result;
    }
    function openConfiguration(row,d){
        const schema=schemas[current];if(!schema||(current==='arp'&&!state.runtime_capabilities?.neighbor_management))return false;
        if(current==='dns'){openEditor(schema,configured(schema.path),null);return true;}
        if(row._editIndex!==undefined){openEditor(schema,row._entry,row._editIndex);return true;}
        if(['arp','dhcp'].includes(current)&&row.mac){openEditor(schema,{ip:row.ip,mac:row.mac,interface:row.interface,hostname:row.hostname||'',lease_time:'12h',comment:'',disabled:false},null);return true;}
        return false;
    }
    function openEditor(schema,entry={},index=null,commentOnly=false){
        const dialog=$('mutation-dialog');dialog.replaceChildren();
        const title=node('div',undefined,'dialog-title');title.append(node('strong',`${index===null&&schema.module!=='dns'?'New ':''}${schema.title}`));const close=node('button','×');close.type='button';close.setAttribute('aria-label','Close configuration dialog');close.onclick=()=>dialog.close();title.append(close);dialog.append(title);
        const form=node('form',undefined,'entry-editor');const tabs=node('div',undefined,'editor-tabs');tabs.append(node('span',commentOnly?'Comment':'General','editor-tab active'));form.append(tabs);
        const body=node('div',undefined,'editor-body'),fields=node('div',undefined,'editor-fields'),actions=node('div',undefined,'editor-buttons'),error=node('div',undefined,'editor-error');error.setAttribute('role','alert');error.hidden=true;
        const inputs={};const available=[...new Set([...(state.desired?.configuration?.addresses||[]).map(x=>x.interface),...(state.desired?.configuration?.interfaces||[]).map(x=>x.name),...configured('services.wireguard.interfaces').map(x=>x.name),state.router.management_interface])];
        for(const [name,label,type,required] of schema.fields){if(commentOnly&&name!=='comment')continue;const line=node('label',undefined,'editor-field');line.append(node('span',label));let input;if(type==='interface'||type==='optional-interface'||type==='bridge-interface'||type==='wg-interface'||type.startsWith('select:')){input=node('select');const choices=type==='wg-interface'?configured('services.wireguard.interfaces').map(e=>e.name):type==='bridge-interface'?configured('interfaces').filter(e=>e.type==='bridge').map(e=>e.name):type.startsWith('select:')?type.slice(7).split(','):type==='optional-interface'?['',...available]:available;choices.forEach(v=>{const option=node('option',v||'any');option.value=v;input.append(option);});}else{input=node('input');input.type=type==='checkbox'?'checkbox':type==='number'?'number':type==='password'?'password':'text';}input.name=name;input.required=!!required;input.setAttribute('aria-label',label);if(type==='checkbox')input.checked=!!entry[name];else input.value=Array.isArray(entry[name])?entry[name].join(', '):entry[name]??(name==='password_action'?(index===null?'replace':'retain'):name==='lease_time'?'12h':name==='start'?100:name==='limit'?100:name==='metric'?10:name==='chain'?(schema.module==='nat'?'postrouting':'forward'):name==='action'?(schema.module==='nat'?'masquerade':'drop'):name==='protocol'?'all':name==='type'&&schema.module==='devices'?'bridge':'');if(type==='number'){input.min='0';input.step='1';}if(name==='ip')input.placeholder='192.168.77.250';if(name==='mac')input.placeholder='02:00:00:00:77:fa';if(name==='cidr')input.placeholder='192.168.77.1/24';if(name==='destination')input.placeholder='192.168.80.0/24 or default';if(type==='password'){input.value='';input.autocomplete='new-password';}if(input.tagName==='SELECT'&&!input.value&&required&&input.options.length)input.selectedIndex=0;inputs[name]={input,type};line.append(input);fields.append(line);}
        fields.append(error);body.append(fields,actions);form.append(body);
        const ok=node('button','OK','button');ok.type='submit';const cancel=node('button','Cancel','button');cancel.type='button';cancel.onclick=()=>dialog.close();const apply=node('button','Apply','button');apply.type='button';actions.append(ok,cancel,apply);
        const hint=node('div','Edits are staged. Apply the revision with Safe Mode to change the router.','editor-hint');if(schema.module==='arp')hint.textContent+=' Disable removes the managed static pin and allows dynamic ARP. Comments persist on the appliance.';if(['wg_interfaces','wg_peers'].includes(schema.module))hint.textContent+=' WireGuard uses a default-deny VPN zone. Add explicit firewall rules for tunnel traffic.';form.append(hint);dialog.append(form);
        if(schema.module==='devices'){
            const sync=()=>{const vlan=inputs.type.input.value==='vlan';for(const key of ['parent','vlan_id']){inputs[key].input.disabled=!vlan;inputs[key].input.required=vlan;}inputs.vlan_filtering.input.disabled=vlan;};
            inputs.type.input.onchange=sync;sync();
        }
        if(['filter','nat'].includes(schema.module)&&!commentOnly){
            const sync=()=>{
                const nat=schema.module==='nat',action=inputs.action.input.value,chain=inputs.chain.input.value;
                for(const key of ['in_interface','out_interface']){const needed=nat?(key==='out_interface'||action==='dnat'):(chain==='forward'||(chain==='input'&&key==='in_interface')||(chain==='output'&&key==='out_interface'));inputs[key].input.disabled=!needed;inputs[key].input.required=needed;if(!needed)inputs[key].input.value='';}
                for(const key of ['source_port','destination_port','to_port'])if(inputs[key]){inputs[key].input.disabled=!['tcp','udp'].includes(inputs.protocol.input.value);}
                if(nat){inputs.to_address.input.disabled=action==='masquerade';inputs.to_address.input.required=action!=='masquerade';}
            };
            inputs.protocol.input.onchange=sync;inputs.chain.input.onchange=sync;inputs.action.input.onchange=()=>{if(schema.module==='nat')inputs.chain.input.value=inputs.action.input.value==='dnat'?'prerouting':'postrouting';sync();};sync();
        }
        let context={revision:state.desired?.revision_id,lock:state.router.configuration_lock_version};
        const submit=async closeAfter=>{if(!form.reportValidity())return;error.hidden=true;const data={};for(const [name,{input,type}] of Object.entries(inputs)){if(input.disabled)continue;if(type==='checkbox')data[name]=input.checked;else if(type==='list')data[name]=input.value.split(/[,\n]/).map(v=>v.trim()).filter(Boolean);else if(type==='number'){if(input.value!=='')data[name]=Number(input.value);}else data[name]=type==='password'?input.value:input.value.trim();}ok.disabled=apply.disabled=true;try{await mutate({module:schema.module,action:commentOnly?'comment':schema.module==='dns'?'update':index===null?'add':'update',index,entry:schema.module==='devices'?{...entry,...data}:data},context);context={revision:state.desired?.revision_id,lock:state.router.configuration_lock_version};if(index===null&&schema.module!=='dns')index=configured(schema.path).length-1;hint.textContent='Saved to staged revision. Use Apply changes… to update the appliance.';if(closeAfter)dialog.close();}catch(e){error.textContent=e.message;error.hidden=false;}finally{ok.disabled=apply.disabled=false;}};
        form.onsubmit=e=>{e.preventDefault();submit(true);};apply.onclick=()=>submit(false);dialog.setAttribute('aria-label',schema.title);openDialog(dialog);Object.values(inputs)[0]?.input.focus();
    }
    function confirmRemoval(row){
        const dialog=$('mutation-dialog');dialog.replaceChildren();const title=node('div',undefined,'dialog-title');title.append(node('strong','Remove entry'));const body=node('div',undefined,'dialog-body');body.append(node('p',`Stage removal of ${row.ip||row.cidr||row.destination||row.interface||row.action||'selected entry'}?`));body.append(node('p','The active router changes only when you apply the revision.','muted'));const error=node('p',undefined,'error');body.append(error);const actions=node('div',undefined,'dialog-actions'),cancel=node('button','Cancel','button'),remove=node('button','Remove','button');cancel.onclick=()=>dialog.close();remove.onclick=async()=>{remove.disabled=true;try{await mutate({module:schemas[current].module,action:'remove',index:row._editIndex});dialog.close();}catch(e){error.textContent=e.message;remove.disabled=false;}};actions.append(cancel,remove);dialog.append(title,body,actions);openDialog(dialog);
    }
    $('new-entry').onclick=e=>{if(!definition().editable){e.preventDefault();return;}const schema=schemas[current];if(schema){e.preventDefault();openEditor(schema,current==='dns'?configured(schema.path):{},null);}else if(!definition().editable)e.preventDefault();};
    for(const action of ['enable','disable','comment','remove']){$(`entry-${action}`).onclick=async()=>{const row=chosen();if(!row||!schemas[current])return;if(action==='remove')return confirmRemoval(row);if(action==='comment')return openEditor(schemas[current],row._entry,row._editIndex,true);try{await mutate({module:schemas[current].module,action,index:row._editIndex});}catch(e){$('state-notice').textContent=e.message;$('state-notice').hidden=false;}};}

    for(const direction of ['up','down'])$(`entry-move-${direction}`).onclick=async()=>{
        const row=chosen();if(!row||!['firewall','nat'].includes(current))return;
        const target=row._editIndex+(direction==='up'?-1:1);
        try{await mutate({module:schemas[current].module,action:`move_${direction}`,index:row._editIndex});sortKey='';const next=lastRows.find(r=>r._editIndex===target);if(next)selected.add(next._index);renderTable(definition());}
        catch(e){$('state-notice').textContent=e.message;$('state-notice').hidden=false;}
    };
    render(true);
    return {
        title:()=>definition().title,
        columns:()=>definition().columns,
        find:()=>{$('table-search').focus();$('table-search').select();},
        isLive:()=>live,
        repaint:()=>{if(!definition().special)renderTable(definition());},
        status:error=>renderStatus(error),
        loading:loading=>{root.classList.toggle('loading',loading);$('refresh-table').disabled=loading;},
        receive:next=>{
            const saved=lastRows.filter(r=>selected.has(r._index)).map(rowIdentity),focus=document.activeElement,focusKey=root.contains(focus)?focus?.dataset.rowKey:null;
            state=next;lastSuccess=Date.parse(next.observed?.recorded_at||'');
            const all=(definition().rows?.()||[]).map((r,_index)=>({...r,_index}));selected=new Set(all.filter(r=>saved.includes(rowIdentity(r))).map(r=>r._index));
            if(anchor!==null){const old=lastRows.find(r=>r._index===anchor);anchor=old?all.find(r=>rowIdentity(r)===rowIdentity(old))?._index??null:null;}
            render(true);if(focusKey)findRow(focusKey)?.focus({preventScroll:true});
        },
    };
};
new window.NurDesktop();
