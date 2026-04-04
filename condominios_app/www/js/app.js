// Configuración
const API_BASE = 'https://gerencia.inversionesares2.com/condominios_app/api/';
const WEB_BASE = 'https://gerencia.inversionesares2.com/condominios_app/';

let user = JSON.parse(localStorage.getItem('user')) || null;
let currentCondominio = JSON.parse(localStorage.getItem('condominio')) || null;
let currentInmueble = null;
let currentDeudas = [];

document.addEventListener('DOMContentLoaded', () => {
    checkAuth();
    setupForms();
});

function checkAuth() {
    if (user) {
        showMainApp();
    } else {
        showLogin();
    }
}

function showView(viewId) {
    document.querySelectorAll('.view').forEach(v => v.classList.add('hidden'));
    document.getElementById(viewId).classList.remove('hidden');
}

function showLogin() {
    showView('login-view');
    document.getElementById('main-nav').style.display = 'none';
}

function showMainApp() {
    document.getElementById('main-nav').style.display = 'flex';
    
    if (user.tipo === 'locatario') {
        showHome();
    } else {
        showAdmin();
    }
}

function goHome() {
    if (user.tipo === 'locatario') {
        showHome();
    } else {
        showAdmin();
    }
}

function setupForms() {
    document.getElementById('login-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const email = document.getElementById('login-email').value;
        const password = document.getElementById('login-password').value;
        
        try {
            const formData = new FormData();
            formData.append('action', 'login');
            formData.append('email', email);
            formData.append('password', password);
            
            const response = await fetch(API_BASE + 'login.php', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            
            if (data.success) {
                user = data.user;
                currentCondominio = data.condominio;
                localStorage.setItem('user', JSON.stringify(user));
                if (currentCondominio) {
                    localStorage.setItem('condominio', JSON.stringify(currentCondominio));
                }
                showMainApp();
            } else {
                let errorMsg = data.error || 'Error al iniciar sesión';
                if (data.debug && data.debug.length > 0) {
                    errorMsg += '<div style="margin-top:15px;background:#1a1a2e;color:#0f0;padding:15px;border-radius:8px;font-family:monospace;font-size:11px;text-align:left;max-height:300px;overflow:auto;">';
                    errorMsg += '<strong style="color:#ff0;">DEBUG:</strong><br>';
                    data.debug.forEach(d => {
                        errorMsg += '<div>' + d + '</div>';
                    });
                    errorMsg += '</div>';
                }
                document.getElementById('login-error').innerHTML = errorMsg;
                document.getElementById('login-error').classList.remove('hidden');
            }
        } catch (err) {
            document.getElementById('login-error').textContent = 'Error de conexión';
            document.getElementById('login-error').classList.remove('hidden');
        }
    });
}

function logout() {
    user = null;
    currentCondominio = null;
    currentInmueble = null;
    localStorage.removeItem('user');
    localStorage.removeItem('condominio');
    showLogin();
}

// Home - Listar inmuebles (Locatario)
async function showHome() {
    showView('home-view');
    
    if (user.tipo !== 'locatario') {
        showAdmin();
        return;
    }
    
    const container = document.getElementById('inmuebles-list');
    
    try {
        const url = `${API_BASE}inmuebles.php?user_id=${user.id}&user_type=${user.tipo}&condominio_db=${currentCondominio?.db_name || ''}`;
        const response = await fetch(url);
        const data = await response.json();
        
        if (data.error) {
            container.innerHTML = `<div class="alert alert-danger">${data.error}</div>`;
            return;
        }
        
        document.getElementById('header-subtitle').textContent = currentCondominio?.nombre || 'Mi Condominio';
        document.getElementById('condominio-info').innerHTML = `
            <h3><i class="fas fa-building"></i> ${currentCondominio?.nombre || ''}</h3>
        `;
        
        if (!data.inmuebles || data.inmuebles.length === 0) {
            container.innerHTML = '<div class="alert alert-info">No tienes inmuebles asignados</div>';
            return;
        }
        
        container.innerHTML = data.inmuebles.map(inm => `
            <div class="property-card" onclick="showDeudas(${inm.id}, '${escapeHtml(inm.nombre)}')">
                <div class="property-info">
                    <h3><i class="fas fa-door-open"></i> ${escapeHtml(inm.nombre)}</h3>
                    <p><i class="fas fa-money-bill-wave"></i> Ver deudas y pagos</p>
                    ${inm.total_deuda > 0 ? `
                        <div class="deuda-badge">
                            <i class="fas fa-exclamation-triangle"></i> Deuda: $${inm.total_deuda.toFixed(2)}
                        </div>
                    ` : `
                        <div class="pagado-badge">
                            <i class="fas fa-check-circle"></i> Al día
                        </div>
                    `}
                </div>
            </div>
        `).join('');
        
    } catch (err) {
        container.innerHTML = `<div class="alert alert-danger">Error: ${err.message}</div>`;
    }
}

// Deudas de un inmueble
async function showDeudas(inmuebleId, nombreInmueble) {
    showView('deudas-view');
    currentInmueble = { id: parseInt(inmuebleId), nombre: nombreInmueble };
    console.log('showDeudas called - inmuebleId:', inmuebleId, 'nombre:', nombreInmueble, 'currentInmueble:', currentInmueble);
    
    const container = document.getElementById('deudas-content');
    
    try {
        const url = `${API_BASE}deudas.php?user_id=${user.id}&user_type=${user.tipo}&condominio_db=${currentCondominio?.db_name}&inmueble_id=${inmuebleId}`;
        console.log('URL:', url);
        const response = await fetch(url);
        const data = await response.json();
        
        if (data.error) {
            container.innerHTML = `<div class="alert alert-danger">${data.error}</div>`;
            if (data.debug) {
                container.innerHTML += '<div style="margin-top:15px;background:#1a1a2e;color:#0f0;padding:15px;border-radius:8px;font-family:monospace;font-size:11px;"><strong>DEBUG:</strong><br>' + data.debug.join('<br>') + '</div>';
            }
            return;
        }
        
        currentDeudas = data.deudas || [];
        const resumen = data.resumen || {};
        
        if (data.debug && data.debug.length > 0) {
            console.log('DEBUG:', data.debug);
        }
        
        const debts = data.deudas || [];
        
        container.innerHTML = `
            <div class="card">
                <h3><i class="fas fa-door-open"></i> ${escapeHtml(nombreInmueble)}</h3>
                
                <div class="resumen-box">
                    <div class="resumen-item">
                        <span class="resumen-label">Total Gastos</span>
                        <span class="resumen-value danger">$${resumen.total_deuda?.toFixed(2) || '0.00'}</span>
                    </div>
                    <div class="resumen-item">
                        <span class="resumen-label">Meses</span>
                        <span class="resumen-value warning">${resumen.meses_pendientes || 0}</span>
                    </div>
                </div>
                
                <button class="btn btn-success" onclick="showPagarForm()" ${resumen.total_deuda <= 0 ? 'disabled' : ''}>
                    <i class="fas fa-credit-card"></i> Realizar Pago
                </button>
                
                <button class="btn btn-primary" onclick="showGastosList()">
                    <i class="fas fa-file-invoice-dollar"></i> Ver Gastos
                </button>
                
                <p class="text-muted mt-2" style="font-size: 12px;">inmueble_id: ${currentInmueble?.id}</p>
            </div>
            
            <h4><i class="fas fa-list"></i> Gastos por Mes</h4>
            ${debts.length === 0 ? '<p class="text-muted">No hay gastos registrados</p>' : ''}
            ${debts.map(d => `
                <div class="deuda-item">
                    <div class="deuda-header">
                        <span class="deuda-mes">${d.mes} ${d.anio}</span>
                    </div>
                    <div class="deuda-details">
                        <div><span>Total:</span> <strong>$${parseFloat(d.total_a_pagar_mes || 0).toFixed(2)}</strong></div>
                        <div><span>Condominio:</span> $${parseFloat(d.condominio || 0).toFixed(2)}</div>
                        <div><span>Fondo Reserva:</span> $${parseFloat(d.fondo_reserva || 0).toFixed(2)}</div>
                    </div>
                </div>
            `).join('')}
        `;
        
    } catch (err) {
        container.innerHTML = `<div class="alert alert-danger">Error: ${err.message}</div>`;
    }
}

// Formulario de pago
function showPagarForm() {
    showView('pagar-view');
    
    const container = document.getElementById('pagar-content');
    const pendientes = currentDeudas.filter(d => d.estado !== 'pagado');
    
    container.innerHTML = `
        <div class="card">
            <h3><i class="fas fa-credit-card"></i> Realizar Pago</h3>
            <p class="text-muted">Inmueble: ${escapeHtml(currentInmueble?.nombre)}</p>
            
            <div id="pago-error" class="alert alert-danger hidden"></div>
            <div id="pago-success" class="alert alert-success hidden"></div>
            
            <form id="pago-form">
                <div class="form-group">
                    <label>Mes a Pagar *</label>
                    <select id="mes_pago" required>
                        <option value="">Seleccionar...</option>
                        ${pendientes.map(d => `
                            <option value="${d.mes}-${d.anio}">${getMonthName(d.mes)} ${d.anio} - Pendiente: $${d.monto_pendiente?.toFixed(2)}</option>
                        `).join('')}
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Monto a Pagar ($) *</label>
                    <input type="number" id="monto_pagar" required step="0.01" min="0.01" placeholder="0.00">
                </div>
                
                <div class="form-group">
                    <label>Tipo de Pago *</label>
                    <select id="tipo_pago" required>
                        <option value="">Seleccionar...</option>
                        <option value="Efectivo">Efectivo</option>
                        <option value="Transferencia">Transferencia</option>
                        <option value="Deposito">Depósito</option>
                        <option value="Pago Movil">Pago Móvil</option>
                        <option value="Zelle">Zelle</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Banco *</label>
                    <input type="text" id="banco" required placeholder="Nombre del banco">
                </div>
                
                <div class="form-group">
                    <label>Número de Referencia *</label>
                    <input type="text" id="referencia" required placeholder="Número de referencia">
                </div>
                
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-check"></i> Confirmar Pago
                </button>
            </form>
        </div>
    `;
    
    document.getElementById('pago-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        await procesarPago();
    });
}

async function procesarPago() {
    const mesPago = document.getElementById('mes_pago').value;
    const monto = document.getElementById('monto_pagar').value;
    const tipoPago = document.getElementById('tipo_pago').value;
    const banco = document.getElementById('banco').value;
    const referencia = document.getElementById('referencia').value;
    
    if (!mesPago || !monto || !tipoPago || !banco || !referencia) {
        document.getElementById('pago-error').textContent = 'Complete todos los campos';
        document.getElementById('pago-error').classList.remove('hidden');
        return;
    }
    
    const [mes, anio] = mesPago.split('-');
    const deuda = currentDeudas.find(d => d.mes == mes && d.anio == anio);
    
    try {
        const formData = new FormData();
        formData.append('action', 'pagar');
        formData.append('user_id', user.id);
        formData.append('user_type', user.tipo);
        formData.append('condominio_db', currentCondominio?.db_name);
        formData.append('inmueble_id', currentInmueble?.id);
        formData.append('deuda_id', deuda?.id || '');
        formData.append('monto', monto);
        formData.append('tipo_pago', tipoPago);
        formData.append('banco', banco);
        formData.append('referencia', referencia);
        formData.append('mes_pago', getMonthName(mes));
        formData.append('anio_pago', anio);
        
        const response = await fetch(API_BASE + 'pagos.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        
        if (data.success) {
            document.getElementById('pago-success').innerHTML = `
                <i class="fas fa-check-circle"></i> ${data.mensaje}
            `;
            document.getElementById('pago-success').classList.remove('hidden');
            document.getElementById('pago-form').reset();
            
            setTimeout(() => {
                showDeudas(currentInmueble.id, currentInmueble.nombre);
            }, 2000);
        } else {
            document.getElementById('pago-error').textContent = data.error || 'Error al procesar pago';
            document.getElementById('pago-error').classList.remove('hidden');
        }
    } catch (err) {
        document.getElementById('pago-error').textContent = 'Error de conexión';
        document.getElementById('pago-error').classList.remove('hidden');
    }
}

// Ver Gastos (Recibos de Cobro)
async function showGastosList() {
    showView('recibos-view');
    
    const container = document.getElementById('recibos-content');
    const inmuebleId = currentInmueble?.id || '';
    console.log('showGastosList - currentInmueble:', currentInmueble, 'inmuebleId:', inmuebleId);
    
    try {
        const url = `${API_BASE}deudas.php?user_id=${user.id}&user_type=${user.tipo}&condominio_db=${currentCondominio?.db_name}&inmueble_id=${inmuebleId}`;
        console.log('URL:', url);
        const response = await fetch(url);
        const data = await response.json();
        
        if (data.error) {
            container.innerHTML = `<div class="alert alert-danger">${data.error}</div>`;
            if (data.debug) {
                container.innerHTML += '<div style="margin-top:15px;background:#1a1a2e;color:#0f0;padding:15px;border-radius:8px;font-family:monospace;font-size:11px;max-height:400px;overflow:auto;"><strong>DEBUG:</strong><br>' + data.debug.join('<br>') + '</div>';
            }
            return;
        }
        
        if (data.debug && data.debug.length > 0) {
            container.innerHTML += '<div style="margin-top:15px;background:#1a1a2e;color:#0f0;padding:15px;border-radius:8px;font-family:monospace;font-size:11px;max-height:400px;overflow:auto;"><strong>DEBUG:</strong><br>' + data.debug.join('<br>') + '</div>';
        }
        
        const debts = data.deudas || [];
        
        // Ordenar por inmueble y mes
        const sortedDebts = debts.sort((a, b) => {
            const nameA = a.inmueble_nombre || 'Inmueble ' + a.inmueble_id;
            const nameB = b.inmueble_nombre || 'Inmueble ' + b.inmueble_id;
            return nameA.localeCompare(nameB);
        });
        
        container.innerHTML = `
            <div class="card">
                <h3><i class="fas fa-file-invoice-dollar"></i> Gastos por Inmueble</h3>
                <p class="text-muted">${currentInmueble?.nombre || ''} - ${sortedDebts.length} propiedades</p>
            </div>
            
            ${sortedDebts.length === 0 ? '<div class="alert alert-info">No hay gastos registrados</div>' : ''}
            ${sortedDebts.map(d => `
                <div class="recibo-item" style="margin-bottom: 15px; padding: 15px; border: 1px solid #ddd; border-radius: 8px; background: white;">
                    <div class="recibo-header" style="background: #28a745; color: white; padding: 10px; margin: -15px -15px 15px -15px; border-radius: 8px 8px 0 0;">
                        <span class="recibo-fecha" style="font-size: 16px; font-weight: bold;">${d.inmueble_nombre || 'Inmueble ' + d.inmueble_id}</span>
                    </div>
                    <div style="background: #f8f9fa; padding: 10px; margin-bottom: 10px; border-radius: 5px;">
                        <strong>Período:</strong> ${d.mes} ${d.anio}
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 5px; font-size: 13px;">
                        <div>Condominio:</div><div style="text-align:right"><strong>$${parseFloat(d.condominio || 0).toFixed(2)}</strong></div>
                        <div>Fondo Reserva:</div><div style="text-align:right"><strong>$${parseFloat(d.fondo_reserva || 0).toFixed(2)}</strong></div>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 15px; background: #007bff; color: white; font-size: 20px; font-weight: bold; margin: 10px -15px -15px -15px; padding: 15px; border-radius: 0 0 8px 8px;">
                        <span>TOTAL:</span> <span>$${parseFloat(d.total_a_pagar_mes || 0).toFixed(2)}</span>
                    </div>
                </div>
            `).join('')}
        `;
    } catch (err) {
        container.innerHTML = `<div class="alert alert-danger">Error: ${err.message}</div>`;
    }
}

// Ver historial de pagos (tabla ingresos)
async function showRecibos() {
    showView('recibos-view');
    
    const container = document.getElementById('recibos-content');
    const inmuebleId = currentInmueble?.id || '';
    
    try {
        const url = `${API_BASE}recibos.php?user_id=${user.id}&user_type=${user.tipo}&condominio_db=${currentCondominio?.db_name}&inmueble_id=${inmuebleId}`;
        const response = await fetch(url);
        const data = await response.json();
        
        if (data.error) {
            container.innerHTML = `<div class="alert alert-danger">${data.error}</div>`;
            if (data.debug) {
                container.innerHTML += '<div style="margin-top:15px;background:#1a1a2e;color:#0f0;padding:15px;border-radius:8px;font-family:monospace;font-size:11px;"><strong>DEBUG:</strong><br>' + data.debug.join('<br>') + '</div>';
            }
            return;
        }
        
        if (data.debug && data.debug.length > 0) {
            console.log('DEBUG:', data.debug);
        }
        
        const pagos = data.pagos || [];
        
        container.innerHTML = `
            <div class="card">
                <h3><i class="fas fa-history"></i> Historial de Pagos</h3>
                <p class="text-muted">${currentInmueble?.nombre || ''}</p>
            </div>
            
            ${pagos.length === 0 ? '<div class="alert alert-info">No hay pagos registrados</div>' : ''}
            ${pagos.map(p => `
                <div class="recibo-item">
                    <div class="recibo-header">
                        <span class="recibo-fecha">${formatDate(p.fecha_pago)}</span>
                        <span class="recibo-estado estado-${p.estado === 'verificado' ? 'pagado' : 'pendiente'}">${p.estado === 'verificado' ? 'Verificado' : (p.estado || 'Pendiente')}</span>
                    </div>
                    <div class="recibo-details">
                        <div><strong>${p.nombre_inmueble || 'Inmueble'}</strong></div>
                        <div>${p.mes_aplicado || p.mes_pago || ''}</div>
                        <div>Monto: $${parseFloat(p.monto || 0).toFixed(2)}</div>
                        <div>Tipo: ${p.tipo_pago || 'N/A'}</div>
                        ${p.referencia_op ? `<div>Ref: ${p.referencia_op}</div>` : ''}
                    </div>
                </div>
            `).join('')}
        `;
        
    } catch (err) {
        container.innerHTML = `<div class="alert alert-danger">Error: ${err.message}</div>`;
    }
}
async function showAdmin() {
    showView('admin-view');
    
    const container = document.getElementById('admin-content');
    container.innerHTML = '<div class="text-center"><i class="fas fa-spinner fa-spin fa-2x"></i> Cargando...</div>';
    
    try {
        const url = `${API_BASE}inmuebles.php?user_id=${user.id}&user_type=${user.tipo}`;
        const response = await fetch(url);
        const data = await response.json();
        
        if (data.error) {
            container.innerHTML = `<div class="alert alert-danger">${data.error}</div>`;
            return;
        }
        
        const condominios = data.condominios || [];
        
        container.innerHTML = `
            <div class="card">
                <h3><i class="fas fa-user-shield"></i> Panel de Administración</h3>
                <p>Bienvenido, ${escapeHtml(user.nombre)}</p>
                <p class="text-muted">Tipo: ${user.tipo}</p>
            </div>
            
            <h4><i class="fas fa-building"></i> Seleccionar Condominio</h4>
            ${condominios.length === 0 ? '<div class="alert alert-info">No hay condominios disponibles</div>' : ''}
            ${condominios.map(c => `
                <div class="property-card" onclick="seleccionarCondominio(${c.id}, '${escapeHtml(c.nombre)}', '${c.db_name}')">
                    <div class="property-info">
                        <h3>${escapeHtml(c.nombre)}</h3>
                        <p>${c.direccion || ''}</p>
                        <div class="badge badge-info">${c.tipo || 'residencial'}</div>
                        <div class="badge badge-secondary">${c.num_inmuebles || 0} inmuebles</div>
                    </div>
                </div>
            `).join('')}
        `;
        
    } catch (err) {
        container.innerHTML = `<div class="alert alert-danger">Error: ${err.message}</div>`;
    }
}

let currentCondominioAdmin = null;

async function seleccionarCondominio(condominioId, nombre, dbName) {
    currentCondominioAdmin = { id: condominioId, nombre: nombre, db_name: dbName };
    showView('admin-view');
    
    const container = document.getElementById('admin-content');
    container.innerHTML = `
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <h3><i class="fas fa-building"></i> ${escapeHtml(nombre)}</h3>
                <button onclick="showAdmin()" class="btn btn-sm btn-secondary"><i class="fas fa-arrow-left"></i> Cambiar</button>
            </div>
        </div>
        
        <div class="menu-grid">
            <div class="menu-item" onclick="showSeccionAdmin('dashboard')">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </div>
            <div class="menu-item" onclick="showSeccionAdmin('locatarios')">
                <i class="fas fa-users"></i>
                <span>Locatarios</span>
            </div>
            <div class="menu-item" onclick="showSeccionAdmin('inmuebles')">
                <i class="fas fa-building"></i>
                <span>Inmuebles</span>
            </div>
            <div class="menu-item" onclick="showSeccionAdmin('deudas')">
                <i class="fas fa-exclamation-triangle"></i>
                <span>Deudas</span>
            </div>
            <div class="menu-item" onclick="showSeccionAdmin('ingresos')">
                <i class="fas fa-arrow-up"></i>
                <span>Ingresos</span>
            </div>
            <div class="menu-item" onclick="showSeccionAdmin('egresos')">
                <i class="fas fa-arrow-down"></i>
                <span>Egresos</span>
            </div>
            <div class="menu-item" onclick="showSeccionAdmin('balance')">
                <i class="fas fa-chart-line"></i>
                <span>Balance</span>
            </div>
            <div class="menu-item" onclick="showSeccionAdmin('empleados')">
                <i class="fas fa-user-tie"></i>
                <span>Empleados</span>
            </div>
            <div class="menu-item" onclick="showSeccionAdmin('nomina')">
                <i class="fas fa-users-cog"></i>
                <span>Nómina</span>
            </div>
            <div class="menu-item" onclick="showSeccionAdmin('pago_nomina')">
                <i class="fas fa-money-check-alt"></i>
                <span>Pago Nómina</span>
            </div>
            <div class="menu-item" onclick="showSeccionAdmin('verificar_pagos')">
                <i class="fas fa-check-circle"></i>
                <span>Verificar Pagos</span>
            </div>
            <div class="menu-item" onclick="showSeccionAdmin('buzon')">
                <i class="fas fa-envelope"></i>
                <span>Buzón</span>
            </div>
            <div class="menu-item" onclick="showSeccionAdmin('gastos')">
                <i class="fas fa-file-invoice-dollar"></i>
                <span>Gastos</span>
            </div>
            <div class="menu-item" onclick="showSeccionAdmin('reportes')">
                <i class="fas fa-chart-bar"></i>
                <span>Reportes</span>
            </div>
        </div>
        
        <div id="seccion-contenido"></div>
    `;
}

async function showSeccionAdmin(seccion) {
    const container = document.getElementById('seccion-contenido');
    container.innerHTML = '<div class="text-center"><i class="fas fa-spinner fa-spin fa-2x"></i> Cargando...</div>';
    
    try {
        const url = `${API_BASE}admin.php?seccion=${seccion}&condominio_db=${currentCondominioAdmin.db_name}`;
        const response = await fetch(url);
        const data = await response.json();
        
        if (data.error && !data.error.includes('no existe')) {
            container.innerHTML = `<div class="alert alert-danger">${data.error}</div>`;
            return;
        }
        
        if (seccion === 'dashboard') {
            container.innerHTML = `
                <div class="card">
                    <h4><i class="fas fa-tachometer-alt"></i> Dashboard</h4>
                    <div class="resumen-box">
                        <div class="resumen-item">
                            <span class="resumen-label">Locatarios</span>
                            <span class="resumen-value">${data.locatarios}</span>
                        </div>
                        <div class="resumen-item">
                            <span class="resumen-label">Inmuebles</span>
                            <span class="resumen-value">${data.inmuebles}</span>
                        </div>
                        <div class="resumen-item">
                            <span class="resumen-label">Ingresos</span>
                            <span class="resumen-value success">$${data.ingresos?.toFixed(2)}</span>
                        </div>
                        <div class="resumen-item">
                            <span class="resumen-label">Egresos</span>
                            <span class="resumen-value danger">$${data.egresos?.toFixed(2)}</span>
                        </div>
                    </div>
                    <div class="balance-box">
                        <span>Balance: </span>
                        <span class="${data.balance >= 0 ? 'text-success' : 'text-danger'}">$${data.balance?.toFixed(2)}</span>
                    </div>
                </div>
            `;
        } else if (seccion === 'balance') {
            container.innerHTML = `
                <div class="card">
                    <h4><i class="fas fa-chart-line"></i> Balance ${data.anio}</h4>
                    <div class="resumen-box">
                        <div class="resumen-item">
                            <span class="resumen-label">Ingresos</span>
                            <span class="resumen-value success">$${data.ingresos?.toFixed(2)}</span>
                        </div>
                        <div class="resumen-item">
                            <span class="resumen-label">Egresos</span>
                            <span class="resumen-value danger">$${data.egresos?.toFixed(2)}</span>
                        </div>
                    </div>
                    <div class="balance-box">
                        <span>Balance: </span>
                        <span class="${data.balance >= 0 ? 'text-success' : 'text-danger'}">$${data.balance?.toFixed(2)}</span>
                    </div>
                </div>
            `;
        } else if (seccion === 'reportes') {
            const reportes = data.reportes || [];
            container.innerHTML = `
                <div class="card mb-3">
                    <h4><i class="fas fa-chart-bar"></i> Reportes</h4>
                    <p class="text-muted">Seleccione un reporte para visualizar</p>
                </div>
                <div class="reportes-grid">
                    <div class="reporte-card border-left-success" onclick="showReporte('deuda_general')">
                        <div class="reporte-icon"><i class="fas fa-file-invoice-dollar"></i></div>
                        <div class="reporte-title">Deudas Mensuales</div>
                        <div class="reporte-subtitle">Estado de Cuentas</div>
                    </div>
                    <div class="reporte-card border-left-info" onclick="showReporte('estado_deudas')">
                        <div class="reporte-icon"><i class="fas fa-chart-bar"></i></div>
                        <div class="reporte-title">Estado de Deudas</div>
                        <div class="reporte-subtitle">Deudas Acumuladas</div>
                    </div>
                    <div class="reporte-card border-left-warning" onclick="showReporte('auditoria')">
                        <div class="reporte-icon"><i class="fas fa-history"></i></div>
                        <div class="reporte-title">Auditoría</div>
                        <div class="reporte-subtitle">Registro de Actividades</div>
                    </div>
                </div>
            `;
        } else if (seccion.startsWith('reporte_')) {
            const reporteId = seccion.replace('reporte_', '');
            let titulo = '';
            let url = '';
            
            switch(reporteId) {
                case 'deuda_general':
                    titulo = 'Deudas Mensuales - Estado de Cuentas';
                    url = '../../modules/condominios/reportes/deuda_general.php';
                    break;
                case 'estado_deudas':
                    titulo = 'Estado de Deudas';
                    url = '../../modules/condominios/reportes/estado_deudas.php';
                    break;
                case 'auditoria':
                    titulo = 'Auditoría';
                    url = '../../modules/condominios/reportes/listar_auditoria.php';
                    break;
            }
            
            container.innerHTML = `
                <div class="card mb-2">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <h4><i class="fas fa-chart-bar"></i> ${titulo}</h4>
                        <button class="btn btn-sm btn-secondary" onclick="showSeccionAdmin('reportes')">
                            <i class="fas fa-arrow-left"></i> Volver
                        </button>
                    </div>
                </div>
                <iframe src="${url}" style="width:100%;height:calc(100vh - 200px);border:none;border-radius:8px;background:white;" frameborder="0"></iframe>
            `;
        } else if (seccion === 'empleados') {
            const items = data.datos || [];
            container.innerHTML = `
                <div class="card">
                    <h4><i class="fas fa-user-tie"></i> Empleados</h4>
                    <p class="text-muted">Total: ${items.length} empleados</p>
                </div>
                ${items.length === 0 ? '<div class="alert alert-info">No hay empleados registrados</div>' : ''}
                ${items.map(item => `
                    <div class="list-item">
                        <div><strong>Cédula:</strong> ${item.cedula || '-'}</div>
                        <div><strong>Nombre:</strong> ${item.nombre || ''} ${item.apellido || ''}</div>
                        <div><strong>Cargo:</strong> ${item.cargo || '-'}</div>
                        <div><strong>Estado:</strong> <span class="${item.estado === 'activo' ? 'text-success' : 'text-danger'}">${item.estado || '-'}</span></div>
                    </div>
                `).join('')}
            `;
        } else if (seccion === 'nomina') {
            const items = data.datos || [];
            container.innerHTML = `
                <div class="card">
                    <h4><i class="fas fa-users-cog"></i> Nómina</h4>
                    <p class="text-muted">Total: ${items.length} nóminas</p>
                </div>
                ${items.length === 0 ? '<div class="alert alert-info">No hay nóminas registradas</div>' : ''}
                ${items.map(item => `
                    <div class="list-item">
                        <div style="grid-column: 1 / -1; font-weight: 600;">${item.nombre || 'Nómina'}</div>
                        <div><strong>Tipo:</strong> ${item.tipo_nomina || '-'}</div>
                        <div><strong>Fecha:</strong> ${formatDate(item.fecha_creacion)}</div>
                    </div>
                `).join('')}
            `;
        } else if (seccion === 'pago_nomina') {
            const items = data.datos || [];
            let html = '';
            if (data.debug && data.debug.length > 0) {
                html = '<div style="margin-top:15px;background:#1a1a2e;color:#0f0;padding:15px;border-radius:8px;font-family:monospace;font-size:11px;"><strong>DEBUG:</strong><br>' + data.debug.join('<br>') + '</div>';
            }
            html += `
                <div class="card">
                    <h4><i class="fas fa-money-check-alt"></i> Pago Nómina</h4>
                    <p class="text-muted">Pagos verificados a empleados (${items.length})</p>
                </div>
                ${items.length === 0 ? '<div class="alert alert-info">No hay pagos de nómina verificados</div>' : ''}
                ${items.map(item => `
                    <div class="list-item">
                        <div><strong>Empleado:</strong> ${item.empleado_nombre || ''} ${item.apellido || ''}</div>
                        <div><strong>Monto $:</strong> $${parseFloat(item.neto_pagar || 0).toFixed(2)}</div>
                        <div><strong>Monto Bs:</strong> ${parseFloat(item.neto_pagar_bolivares || 0).toFixed(2)}</div>
                        <div><strong>Tipo:</strong> ${item.tipo_pago || '-'}</div>
                        <div><strong>Fecha:</strong> ${formatDate(item.fecha_pago)}</div>
                    </div>
                `).join('')}
            `;
            container.innerHTML = html;
        } else if (seccion === 'verificar_pagos') {
            const items = data.datos || [];
            container.innerHTML = `
                <div class="card">
                    <h4><i class="fas fa-check-circle"></i> Verificar Pagos de Nómina</h4>
                    <p class="text-muted">Pagos pendientes de verificación</p>
                </div>
                ${items.length === 0 ? '<div class="alert alert-info">No hay pagos pendientes</div>' : ''}
                ${items.map(item => `
                    <div class="list-item">
                        <div><strong>Empleado:</strong> ${item.empleado_nombre || '-'} ${item.apellido || ''}</div>
                        <div><strong>Cédula:</strong> ${item.cedula || '-'}</div>
                        <div><strong>Fecha:</strong> ${formatDate(item.fecha_pago)}</div>
                        <div><strong>Monto $:</strong> $${parseFloat(item.neto_pagar || 0).toFixed(2)}</div>
                        <div><strong>Monto Bs:</strong> ${parseFloat(item.neto_pagar_bolivares || 0).toFixed(2)}</div>
                        <div><strong>Tipo:</strong> ${item.tipo_pago || '-'}</div>
                    </div>
                `).join('')}
            `;
        } else if (seccion === 'buzon') {
            const items = data.datos || [];
            container.innerHTML = `
                <div class="card">
                    <h4><i class="fas fa-envelope"></i> Buzón de Mensajes</h4>
                    <p class="text-muted">Mensajes recibidos</p>
                </div>
                ${items.length === 0 ? '<div class="alert alert-info">No hay mensajes</div>' : ''}
                ${items.map(item => `
                    <div class="list-item" style="border-left: 4px solid ${item.leido ? '#e2e8f0' : '#3182ce'}">
                        <div style="grid-column: 1 / -1; font-weight: 600;">${item.asunto || '-'}</div>
                        <div><strong>De:</strong> ${item.remitente || '-'}</div>
                        <div><strong>Fecha:</strong> ${formatDate(item.fecha)}</div>
                        <div style="grid-column: 1 / -1; margin-top: 8px;">${item.mensaje || ''}</div>
                    </div>
                `).join('')}
            `;
        } else if (seccion === 'gastos') {
            const items = data.datos || [];
            container.innerHTML = `
                <div class="card">
                    <h4><i class="fas fa-file-invoice-dollar"></i> Gastos por Mes</h4>
                    <p class="text-muted">Total: ${items.length} registros</p>
                </div>
                ${items.length === 0 ? '<div class="alert alert-info">No hay gastos registrados</div>' : ''}
                ${items.map(item => `
                    <div class="list-item">
                        <div><strong>Período:</strong> ${item.mes} ${item.anio}</div>
                        <div><strong>Inmueble:</strong> ${item.inmueble_nombre || '-'}</div>
                        <div><strong>Condominio:</strong> $${parseFloat(item.condominio || 0).toFixed(2)}</div>
                        <div><strong>Fondo Reserva:</strong> $${parseFloat(item.fondo_reserva || 0).toFixed(2)}</div>
                        <div><strong>Total:</strong> <strong>$${parseFloat(item.total_a_pagar_mes || 0).toFixed(2)}</strong></div>
                    </div>
                `).join('')}
            `;
        } else {
            const items = data.datos || [];
            container.innerHTML = `
                <div class="card">
                    <h4><i class="fas fa-list"></i> ${seccion.charAt(0).toUpperCase() + seccion.slice(1)}</h4>
                    <p class="text-muted">Total: ${items.length} registros</p>
                </div>
                ${items.length === 0 ? '<div class="alert alert-info">No hay datos</div>' : ''}
                ${items.map(item => `
                    <div class="list-item">
                        ${Object.entries(item).map(([key, val]) => `
                            <div><strong>${key}:</strong> ${val !== null ? val : '-'}</div>
                        `).join('')}
                    </div>
                `).join('')}
            `;
        }
        
    } catch (err) {
        container.innerHTML = `<div class="alert alert-danger">Error: ${err.message}</div>`;
    }
}

// Utilidades
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
}

function getMonthName(monthNum) {
    const meses = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    if (isNaN(monthNum)) return monthNum; // Ya es nombre de mes
    return meses[parseInt(monthNum)] || monthNum;
}

function formatDate(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    return d.toLocaleDateString('es-ES');
}

function showReporte(reporteId) {
    showSeccionAdmin('reporte_' + reporteId);
}
