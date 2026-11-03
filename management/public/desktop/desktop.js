(() => {
    'use strict';
    const element = (tag, text, className) => {
        const node = document.createElement(tag);
        if (text !== undefined) node.textContent = text;
        if (className) node.className = className;
        return node;
    };
    const number = (value, fallback, min, max) => Number.isFinite(value) ? Math.min(max, Math.max(min, value)) : fallback;
    const own = (object, key) => Object.prototype.hasOwnProperty.call(object, key);
    const legalPanel = panel => typeof panel === 'string' && /^[a-z][a-z0-9-]{0,48}$/.test(panel) || typeof panel === 'string' && /^unsupported:[A-Za-z0-9 -]{1,40}$/.test(panel);

    window.NurDesktop = class {
        constructor() {
            this.state = JSON.parse(document.getElementById('router-desktop-state').textContent);
            this.options = JSON.parse(document.getElementById('router-desktop-options').textContent);
            this.key = `nur.wincontrol.desktop.v1.user:${this.options.userId}.router:${this.options.routerId}`;
            this.area = document.getElementById('desktop-area');
            this.taskbar = document.getElementById('desktop-taskbar');
            this.notice = document.getElementById('workspace-status');
            this.template = document.getElementById('panel-template');
            this.windows = [];
            this.sequence = 0;
            this.active = null;
            this.z = 0;
                        this.request = null;
            this.columnDefinitions = new Map();
            this.saved = this.load();
            this.workspace = own(this.saved.workspaces, this.saved.active) ? this.saved.active : 'own';
            this.bindShell();
            this.restore();
            this.refresh();
            this.liveTimer = setInterval(() => {
                if (!document.hidden && this.windows.some(w => !w.minimized && w.view.isLive())) this.refresh();
                this.windows.forEach(w => w.view.status());
            }, 30000);
            window.addEventListener('pagehide', () => { this.save(); this.request?.abort(); clearInterval(this.liveTimer); });
            window.addEventListener('resize', () => {
                this.windows.forEach(w => this.place(w));
                this.layoutTaskbar();
            });
            window.visualViewport?.addEventListener('resize', () => this.windows.forEach(w => this.place(w)));
            window.addEventListener('storage', event => {
                if (event.key === this.key) this.message('Workspace changed in another tab. Reload to use its saved layout.');
            });
        }

        load() {
            const empty = { version: 1, active: 'own', workspaces: { own: { name: '<own>', windows: null, columns: {} } } };
            try {
                const raw = localStorage.getItem(this.key);
                if (!raw) return empty;
                if (raw.length > 250000) throw new Error('Workspace data exceeds the supported size.');
                const saved = JSON.parse(raw);
                if (saved.version !== 1 || !saved.workspaces || Array.isArray(saved.workspaces)) throw new Error('Invalid workspace format.');
                const result = { version: 1, active: typeof saved.active === 'string' ? saved.active : 'own', workspaces: Object.create(null) };
                for (const [key, workspace] of Object.entries(saved.workspaces).slice(0, 20)) {
                    if (!/^(own|ws-[a-z0-9-]+)$/.test(key) || !workspace || typeof workspace.name !== 'string') continue;
                    result.workspaces[key] = { name: workspace.name.slice(0, 48), windows: Array.isArray(workspace.windows) ? workspace.windows.slice(0, 32) : null, columns: workspace.columns && typeof workspace.columns === 'object' && !Array.isArray(workspace.columns) ? workspace.columns : {} };
                }
                if (!own(result.workspaces, 'own')) result.workspaces.own = empty.workspaces.own;
                return result;
            } catch (error) {
                this.message('Saved layout could not be read. A default workspace is open; use Reset layout to replace it.');
                return empty;
            }
        }

        data() { return this.saved.workspaces[this.workspace]; }
        message(text) { this.notice.textContent = text; this.notice.title = text; }
        save() {
            if (this.restoring) return;
            const workspace = this.data();
            workspace.windows = [...this.windows].sort((a, b) => a.z - b.z).map(w => ({ panel: w.panel, x: w.x, y: w.y, width: w.width, height: w.height, maximized: w.maximized, minimized: w.minimized, live: !!w.live }));
            this.saved.active = this.workspace;
            try {
                localStorage.setItem(this.key, JSON.stringify(this.saved));
                this.message('Saved in this browser');
            } catch (error) { this.message('Layout could not be saved in this browser. Your current windows remain open.'); }
        }

        restore(explicitPanel) {
            this.restoring = true;
            this.windows.forEach(w => { w.root.querySelectorAll('dialog[open]').forEach(d => d.close()); w.root.remove(); });
            this.windows = [];
            this.active = null;
            this.taskbar.replaceChildren();
            const layout = this.data().windows;
            const requested = explicitPanel || new URLSearchParams(location.search).get('panel');
            if (layout !== null) {
                for (const model of layout) if (model && legalPanel(model.panel)) this.open(model.panel, model);
            } else this.open(legalPanel(requested) ? requested : 'arp', { maximized: true });
            if (legalPanel(explicitPanel)) this.open(explicitPanel);
            const active = [...this.windows].reverse().find(w => !w.minimized);
            if (active) this.focus(active, false);
            this.restoring = false;
            this.renderWorkspaceSelect();
            this.layoutTaskbar();
            this.save();
        }

        open(panel, stored) {
            if (!legalPanel(panel)) return;
            const existing = this.windows.find(w => w.panel === panel);
            if (existing) { existing.minimized = false; this.place(existing); this.focus(existing); this.save(); return existing; }
            if (this.windows.length >= 32) { this.message('Close a window before opening more than 32 panels.'); return; }
            const root = this.template.content.firstElementChild.cloneNode(true);
            const id = ++this.sequence;
            root.querySelectorAll('[id]').forEach(control => { control.dataset.control = control.id; control.id = `window-${id}-${control.id}`; });
            root.id = `desktop-window-${id}`;
            root.querySelectorAll('dialog').forEach(dialog => dialog.setAttribute('aria-label', ({'row-dialog':'Entry details','mutation-dialog':'Configuration entry','columns-dialog':'Columns','apply-dialog':'Apply staged configuration'})[dialog.dataset.control] || 'Dialog'));
            root.dataset.windowId = id;
            root.setAttribute('role', 'region');
            const index = this.windows.length;
            const w = { root, panel, x: number(stored?.x, 24 + index * 26, 0, 10000), y: number(stored?.y, 22 + index * 26, 0, 10000), width: number(stored?.width, Math.min(1050, this.area.clientWidth - 48), 340, 10000), height: number(stored?.height, Math.min(620, this.area.clientHeight - 48), 220, 10000), maximized: stored?.maximized === true, minimized: stored?.minimized === true, live: stored?.live === true, z: ++this.z };
            this.area.append(root);
            this.windows.push(w);
            const title = root.querySelector('.window-title');
            title.tabIndex = 0;
            title.setAttribute('aria-label', 'Window title. Arrow keys move; Shift and arrows resize.');
            root.addEventListener('pointerdown', () => this.focus(w, false), { capture: true });
            root.addEventListener('focusin', () => this.focus(w, false));
            title.addEventListener('dblclick', event => { if (!event.target.closest('button')) this.maximize(w); });
            title.addEventListener('pointerdown', event => { if (!event.target.closest('button')) this.startDrag(event, w, 'move'); });
            title.addEventListener('keydown', event => this.titleKey(event, w));
            for (const direction of ['n','s','e','w','ne','nw','se','sw']) {
                const handle = element('div', undefined, `window-resizer resize-${direction}`);
                handle.setAttribute('aria-hidden', 'true');
                handle.addEventListener('pointerdown', event => this.startDrag(event, w, direction));
                root.append(handle);
            }
            w.view = window.createNurDesktopPanel(root, this, w);
            title.title = `${w.view.title()} — drag to move; double-click to maximize or restore`;
            this.place(w);
            if (!w.minimized) this.focus(w, false);
            this.layoutTaskbar();
            this.save();
            return w;
        }

        place(w) {
            const maxWidth = Math.max(1, this.area.clientWidth), maxHeight = Math.max(1, this.area.clientHeight);
            const width = Math.min(maxWidth, Math.max(Math.min(420, maxWidth), w.width));
            const height = Math.min(maxHeight, Math.max(Math.min(240, maxHeight), w.height));
            const x = Math.min(Math.max(0, w.x), Math.max(0, maxWidth - width));
            const y = Math.min(Math.max(0, w.y), Math.max(0, maxHeight - height));
            w.root.hidden = w.minimized;
            w.root.classList.toggle('maximized', w.maximized);
            Object.assign(w.root.style, { left: `${w.maximized ? 0 : x}px`, top: `${w.maximized ? 0 : y}px`, width: `${w.maximized ? maxWidth : width}px`, height: `${w.maximized ? maxHeight : height}px`, zIndex: w.z });
            const button = w.root.querySelector('[data-control="window-maximize"]');
            button.textContent = w.maximized ? '❐' : '□';
            button.title = w.maximized ? 'Restore window' : 'Maximize window';
            button.setAttribute('aria-label', w.maximized ? 'Restore window' : 'Maximize window');
        }

        focus(w, moveFocus = true) {
            if (!w || w.minimized || !w.view) return;
            if (this.active !== w) { w.z = ++this.z; this.active = w; }
            this.windows.forEach(other => { other.root.classList.toggle('active-window', other === w); other.root.style.zIndex = other.z; });
            document.body.dataset.panel = w.panel;
            document.querySelectorAll('.sidebar [data-panel]').forEach(a => a.classList.toggle('active', a.dataset.panel === w.panel));
            const url = new URL(location.href); url.searchParams.set('panel', w.panel); history.replaceState(null, '', url);
            this.layoutTaskbar();
            if (moveFocus) w.root.querySelector('.window-title').focus({ preventScroll: true });
        }

        minimize(w) {
            w.minimized = true;
            this.place(w);
            if (this.active === w) this.active = null;
            this.focus([...this.windows].filter(other => !other.minimized).sort((a,b) => b.z-a.z)[0]);
            this.layoutTaskbar();
            this.taskbar.querySelector(`[data-task-panel="${CSS.escape(w.panel)}"]`)?.focus();
            this.save();
        }
        maximize(w) { w.maximized = !w.maximized; w.minimized = false; this.place(w); this.focus(w); this.save(); }
        close(w) {
            w.root.querySelectorAll('dialog[open]').forEach(d => d.close());
            w.root.remove();
            this.windows = this.windows.filter(other => other !== w);
            if (this.active === w) this.active = null;
            this.focus([...this.windows].filter(other => !other.minimized).sort((a,b) => b.z-a.z)[0]);
            this.layoutTaskbar();
            if (!this.active) document.getElementById('workspace-select').focus();
            this.save();
        }
        layoutTaskbar() {
            const focused = document.activeElement?.dataset.taskPanel;
            this.taskbar.replaceChildren(...this.windows.filter(w => w.view).map(w => {
                const button = element('button', `${w.minimized ? '▁ ' : ''}${w.view.title()}`, 'desktop-task' + (this.active === w ? ' active' : ''));
                button.type = 'button'; button.dataset.taskPanel = w.panel; button.setAttribute('aria-pressed', String(this.active === w)); button.title = `${w.view.title()}${w.minimized ? ' — restore' : ' — focus'}`;
                button.onclick = () => { w.minimized = false; this.place(w); this.focus(w); this.save(); };
                return button;
            }));
            this.taskbar.hidden = this.windows.length < 2 && !this.windows.some(w => w.minimized);
            this.area.classList.toggle('with-taskbar', !this.taskbar.hidden);
            document.getElementById('window-count').textContent = this.windows.length;
            if (focused) [...this.taskbar.children].find(button => button.dataset.taskPanel === focused)?.focus({ preventScroll: true });
            this.windows.forEach(w => { if (w.view) this.place(w); });
        }

        startDrag(event, w, mode) {
            if (event.button !== 0 || w.maximized) return;
            event.preventDefault();
            this.focus(w, false);
            const rect = w.root.getBoundingClientRect(), area = this.area.getBoundingClientRect();
            w.x = rect.left-area.left; w.y = rect.top-area.top; w.width = rect.width; w.height = rect.height;
            const start = { x: event.clientX, y: event.clientY, left: w.x, top: w.y, width: w.width, height: w.height };
            const target = event.currentTarget; target.setPointerCapture(event.pointerId);
            document.body.classList.add('desktop-dragging');
            const move = e => {
                const dx = e.clientX-start.x, dy = e.clientY-start.y;
                if (mode === 'move') { w.x = start.left+dx; w.y = start.top+dy; }
                else {
                    if (mode.includes('e')) w.width = Math.max(Math.min(420,this.area.clientWidth),start.width+dx);
                    if (mode.includes('s')) w.height = Math.max(Math.min(240,this.area.clientHeight),start.height+dy);
                    if (mode.includes('w')) { w.width = Math.max(Math.min(420,this.area.clientWidth),start.width-dx); w.x = start.left+start.width-w.width; }
                    if (mode.includes('n')) { w.height = Math.max(Math.min(240,this.area.clientHeight),start.height-dy); w.y = start.top+start.height-w.height; }
                }
                this.place(w);
            };
            const finish = () => {
                target.removeEventListener('pointermove',move);target.removeEventListener('pointerup',finish);target.removeEventListener('pointercancel',finish);
                if(target.hasPointerCapture(event.pointerId))target.releasePointerCapture(event.pointerId);
                const rect=w.root.getBoundingClientRect(),area=this.area.getBoundingClientRect();Object.assign(w,{x:rect.left-area.left,y:rect.top-area.top,width:rect.width,height:rect.height});
                document.body.classList.remove('desktop-dragging');this.save();
            };
            target.addEventListener('pointermove',move);target.addEventListener('pointerup',finish);target.addEventListener('pointercancel',finish);
        }
        titleKey(event,w) {
            if(event.target!==event.currentTarget || !event.key.startsWith('Arrow'))return;
            event.preventDefault();if(w.maximized)return;
            const step=event.ctrlKey?1:10;
            if(event.shiftKey){w.width+=event.key==='ArrowRight'?step:event.key==='ArrowLeft'?-step:0;w.height+=event.key==='ArrowDown'?step:event.key==='ArrowUp'?-step:0;}
            else {w.x+=event.key==='ArrowRight'?step:event.key==='ArrowLeft'?-step:0;w.y+=event.key==='ArrowDown'?step:event.key==='ArrowUp'?-step:0;}
            w.x=Math.max(0,w.x);w.y=Math.max(0,w.y);w.width=Math.max(420,w.width);w.height=Math.max(240,w.height);this.place(w);this.save();
        }

        setState(next) { this.state=next;this.windows.forEach(w=>w.view?.receive(next)); }
        async refresh() {
            if (this.refreshPromise) return this.refreshPromise;
            this.request=new AbortController();this.windows.forEach(w=>w.view?.loading(true));
            this.refreshPromise=(async()=>{
                try {
                    const response=await fetch(`${this.options.stateUrl}?refresh=1`,{signal:this.request.signal,headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'});
                    const next=await response.json();if(!response.ok||next.ok===false)throw new Error(next.error?.message||next.message||'Appliance refresh failed');
                    // An older observation must not replace a revision staged while this request ran.
                    if(Number(next.router.configuration_lock_version)<Number(this.state.router.configuration_lock_version))return;
                    this.windows.forEach(w=>{const notice=w.root.querySelector('[data-control="state-notice"]');if(notice.dataset.refreshError){notice.hidden=true;delete notice.dataset.refreshError;}});
                    this.setState(next);
                }catch(error){if(error.name!=='AbortError'){this.windows.forEach(w=>{w.view.status(error);const notice=w.root.querySelector('[data-control="state-notice"]');notice.textContent=`${error.message}. Previously observed entries are retained.`;notice.dataset.refreshError='1';notice.hidden=false;});}}
                finally{this.windows.forEach(w=>w.view.loading(false));this.refreshPromise=null;}
            })();return this.refreshPromise;
        }

        columns(panel,definitions) {
            this.columnDefinitions.set(panel,definitions);
            const raw=own(this.data().columns,panel)?this.data().columns[panel]:{}, prefs=raw&&typeof raw==='object'?raw:{};
            const order=Array.isArray(prefs.order)?prefs.order:[],hidden=Array.isArray(prefs.hidden)?prefs.hidden:[];
            const keys=[...new Set([...order.filter(k=>typeof k==='string'&&definitions.some(c=>c.key===k)&&k!=='_space'),...definitions.filter(c=>c.key!=='_space').map(c=>c.key)])];
            const result=keys.filter(key=>!hidden.includes(key)).map(key=>{const def=definitions.find(c=>c.key===key);const width=prefs.widths&&own(prefs.widths,key)?prefs.widths[key]:null;return {...def,width:Number.isFinite(width)?`${number(width,120,48,1600)}px`:def.width};});
            if(!result.some(c=>c.label))return definitions;
            const space=definitions.find(c=>c.key==='_space');if(space)result.push(space);return result;
        }
        columnPrefs(panel) {
            const current=own(this.data().columns,panel)?this.data().columns[panel]:null;
            if(!current||typeof current!=='object'||Array.isArray(current))this.data().columns[panel]={order:[],hidden:[],widths:{}};
            const prefs=this.data().columns[panel];
            if(!Array.isArray(prefs.order))prefs.order=[];if(!Array.isArray(prefs.hidden))prefs.hidden=[];if(!prefs.widths||typeof prefs.widths!=='object'||Array.isArray(prefs.widths))prefs.widths={};return prefs;
        }
        installColumn(root,th,panel,column,repaint) {
            th.draggable=true;
            th.addEventListener('dragstart',event=>{this.columnDrag={panel,key:column.key};event.dataTransfer.setData('text/plain',column.key);event.dataTransfer.effectAllowed='move';th.classList.add('column-dragging');});
            th.addEventListener('dragend',()=>{this.columnDrag=null;th.classList.remove('column-dragging');});
            th.addEventListener('dragover',event=>{if(this.columnDrag?.panel===panel){event.preventDefault();event.dataTransfer.dropEffect='move';}});
            th.addEventListener('drop',event=>{if(this.columnDrag?.panel!==panel)return;event.preventDefault();const prefs=this.columnPrefs(panel),all=this.columns(panel,this.columnDefinitions.get(panel)).filter(c=>c.key!=='_space').map(c=>c.key),from=all.indexOf(this.columnDrag.key),to=all.indexOf(column.key);if(from<0||to<0)return;all.splice(from,1);all.splice(to,0,this.columnDrag.key);prefs.order=all;this.columnDrag=null;repaint();this.save();});
            const handle=element('span',undefined,'column-resizer');handle.draggable=false;handle.tabIndex=0;handle.setAttribute('role','separator');handle.setAttribute('aria-orientation','vertical');handle.setAttribute('aria-label',`Resize ${column.label||'flags'} column`);handle.setAttribute('aria-valuemin','48');handle.setAttribute('aria-valuemax','1600');handle.setAttribute('aria-valuenow',Math.round(th.getBoundingClientRect().width)||parseFloat(column.width)||100);
            handle.onkeydown=event=>{if(!['ArrowLeft','ArrowRight'].includes(event.key))return;event.preventDefault();event.stopPropagation();const width=number(th.getBoundingClientRect().width+(event.key==='ArrowRight'?10:-10),120,48,1600);this.columnPrefs(panel).widths[column.key]=width;repaint();this.save();root.querySelector(`[data-column-key="${CSS.escape(column.key)}"] .column-resizer`)?.focus();};
            handle.onpointerdown=event=>{
                event.preventDefault();event.stopPropagation();th.draggable=false;
                const start=event.clientX,width=th.getBoundingClientRect().width;handle.setPointerCapture(event.pointerId);
                const move=e=>{const next=number(width+e.clientX-start,120,48,1600);this.columnPrefs(panel).widths[column.key]=next;th.style.width=`${next}px`;handle.setAttribute('aria-valuenow',Math.round(next));};
                const finish=()=>{handle.removeEventListener('pointermove',move);handle.removeEventListener('pointerup',finish);handle.removeEventListener('pointercancel',finish);if(handle.hasPointerCapture(event.pointerId))handle.releasePointerCapture(event.pointerId);th.draggable=true;repaint();this.save();};
                handle.addEventListener('pointermove',move);handle.addEventListener('pointerup',finish);handle.addEventListener('pointercancel',finish);
            };th.append(handle);
        }
        editColumns(root,panel,definitions,repaint) {
            const dialog=root.querySelector('[data-control="columns-dialog"]');dialog.replaceChildren();
            const title=element('div',undefined,'dialog-title');title.append(element('strong','Columns'));const close=element('button','×');close.type='button';close.setAttribute('aria-label','Close columns');close.onclick=()=>dialog.close();title.append(close);dialog.append(title);
            const body=element('div',undefined,'dialog-body columns-body');body.append(element('p','Drag headers to reorder. Drag a separator to resize; arrow keys resize a focused separator.'));
            const prefs=this.columnPrefs(panel),visible=this.columns(panel,definitions).filter(c=>c.key!=='_space').map(c=>c.key),order=[...new Set([...prefs.order,...definitions.map(c=>c.key)])].filter(k=>definitions.some(c=>c.key===k)&&k!=='_space');
            let hidden=definitions.filter(c=>c.key!=='_space'&&!visible.includes(c.key)).map(c=>c.key);
            const list=element('div',undefined,'column-list'),error=element('p',undefined,'error');error.setAttribute('role','alert');
            const render=focusKey=>{list.replaceChildren(...order.map((key,index)=>{const def=definitions.find(c=>c.key===key),row=element('div',undefined,'column-option'),label=element('label'),input=element('input');input.type='checkbox';input.checked=!hidden.includes(key);input.dataset.preferenceKey=key;input.onchange=()=>{if(input.checked)hidden=hidden.filter(k=>k!==key);else hidden.push(key);};label.append(input,document.createTextNode(def.label||'Flags'));row.append(label);for(const [direction,delta]of [['Up',-1],['Down',1]]){const button=element('button',direction,'button');button.type='button';button.disabled=index+delta<0||index+delta>=order.length;button.setAttribute('aria-label',`Move ${def.label||'flags'} ${direction.toLowerCase()}`);button.onclick=()=>{[order[index],order[index+delta]]=[order[index+delta],order[index]];render(key);};row.append(button);}return row;}));if(focusKey)[...list.querySelectorAll('input')].find(i=>i.dataset.preferenceKey===focusKey)?.focus();};render();body.append(list,error);dialog.append(body);
            const actions=element('div',undefined,'dialog-actions'),reset=element('button','Reset columns','button'),cancel=element('button','Cancel','button'),apply=element('button','Apply','button');
            reset.onclick=()=>{delete this.data().columns[panel];repaint();this.save();dialog.close();};cancel.onclick=()=>dialog.close();apply.onclick=()=>{if(!definitions.some(c=>c.label&&!hidden.includes(c.key))){error.textContent='Keep at least one labelled column visible.';return;}prefs.order=order;prefs.hidden=hidden;repaint();this.save();dialog.close();};actions.append(reset,cancel,apply);dialog.append(actions);dialog.showModal();
        }

        bindShell() {
            document.querySelectorAll('.sidebar [data-panel]').forEach(link=>{
                if(link.dataset.panel.startsWith('unsupported:')){link.classList.add('unsupported-module');link.title='Unsupported module — information only';link.append(element('small','Unavailable','module-availability'));}
                link.addEventListener('click',event=>{if(event.ctrlKey||event.metaKey||event.shiftKey)return;event.preventDefault();this.open(link.dataset.panel);});
            });
            document.getElementById('workspace-select').onchange=event=>{this.save();this.workspace=event.target.value;this.restore();};
            document.getElementById('workspace-manage').onclick=()=>this.workspaceDialog();
            document.getElementById('global-find').onclick=()=>this.active?.view.find();
            document.addEventListener('keydown',event=>{
                if(document.querySelector('dialog[open]'))return;
                if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='f'&&this.active){event.preventDefault();this.active.view.find();}
                if(event.altKey&&event.key==='F6'){event.preventDefault();const windows=this.windows.filter(w=>!w.minimized);if(windows.length)this.focus(windows[(windows.indexOf(this.active)+(event.shiftKey?windows.length-1:1))%windows.length]);this.save();}
                if(event.altKey&&event.key==='F9'&&this.active){event.preventDefault();this.minimize(this.active);}
                if(event.altKey&&event.key==='F10'&&this.active){event.preventDefault();this.maximize(this.active);}
                if(event.ctrlKey&&event.altKey&&event.key.toLowerCase()==='w'&&this.active){event.preventDefault();this.close(this.active);}
            });
        }
        renderWorkspaceSelect() {
            const select=document.getElementById('workspace-select');select.replaceChildren(...Object.entries(this.saved.workspaces).map(([key,workspace])=>{const option=element('option',workspace.name);option.value=key;return option;}));select.value=this.workspace;
        }
        workspaceDialog() {
            const dialog=document.getElementById('workspace-dialog');dialog.replaceChildren();
            const title=element('div',undefined,'dialog-title');title.append(element('strong','Workspaces'));const close=element('button','×');close.type='button';close.setAttribute('aria-label','Close workspaces');close.onclick=()=>dialog.close();title.append(close);dialog.append(title);
            const body=element('div',undefined,'dialog-body');body.append(element('p','Layouts and column preferences are saved automatically in this browser, separately for your account and this router.'));
            const label=element('label','Workspace name'),name=element('input');name.value=this.data().name;name.maxLength=48;name.setAttribute('aria-label','Workspace name');label.append(name);body.append(label);
            const hint=element('p','Keyboard: Alt+F6 switches windows; Alt+F9 minimizes; Alt+F10 maximizes/restores. Focus a title and use arrows to move, Shift+arrows to resize.','muted');body.append(hint);
            const error=element('p',undefined,'error');error.setAttribute('role','alert');body.append(error);dialog.append(body);
            const actions=element('div',undefined,'dialog-actions');
            const action=(text,callback)=>{const b=element('button',text,'button');b.type='button';b.onclick=callback;actions.append(b);};
            const valid=()=>{if(!name.value.trim()){error.textContent='Enter a workspace name.';return false;}return true;};
            action('Save as new',()=>{if(!valid())return;if(Object.keys(this.saved.workspaces).length>=20){error.textContent='Delete a workspace before saving more than 20.';return;}this.save();const key=`ws-${Date.now().toString(36)}-${Math.random().toString(36).slice(2,7)}`;this.saved.workspaces[key]=JSON.parse(JSON.stringify(this.data()));this.saved.workspaces[key].name=name.value.trim();this.workspace=key;this.save();this.renderWorkspaceSelect();dialog.close();});
            action('Rename',()=>{if(!valid())return;this.data().name=name.value.trim();this.save();this.renderWorkspaceSelect();dialog.close();});
            action('Reset layout',()=>{this.data().windows=null;this.data().columns={};this.restore('arp');dialog.close();});
            if(this.workspace!=='own')action('Delete workspace',()=>{delete this.saved.workspaces[this.workspace];this.workspace='own';this.restore();dialog.close();});
            action('Close',()=>dialog.close());dialog.append(actions);dialog.showModal();name.focus();name.select();
        }
    };
})();
