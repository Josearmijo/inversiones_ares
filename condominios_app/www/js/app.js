// Configuración
const API_BASE = 'https://gerencia.inversionesares2.com/condominios_app/api/';
const WEB_BASE = 'https://gerencia.inversionesares2.com/';
const BASE_URL = 'https://gerencia.inversionesares2.com/condominios_app/';

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

function togglePasswordVisibility(inputId) {
    const input = document.getElementById(inputId);
    const icon = input.nextElementSibling.querySelector('i');
    
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'fas fa-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'fas fa-eye';
    }
}

function setupForms() {
    document.getElementById('login-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const password = document.getElementById('login-password').value;
        
        try {
            const formData = new FormData();
            formData.append('action', 'login');
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
            console.error('Login error:', err);
            document.getElementById('login-error').innerHTML = `
                <strong>Error de conexión:</strong> ${err.message}
                <div style="margin-top:15px;background:#1a1a2e;color:#0f0;padding:15px;border-radius:8px;font-family:monospace;font-size:11px;">
                    <strong style="color:#ff0;">DEBUG JS:</strong><br>
                    <div>URL: ${API_BASE}login.php</div>
                    <div>Error: ${err.message}</div>
                </div>
            `;
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
            container.innerHTML = '<div class="alert alert-danger">' + data.error + '</div>';
            return;
        }
        
        document.getElementById('header-subtitle').textContent = currentCondominio?.nombre || 'Mi Condominio';
        document.getElementById('condominio-info').innerHTML = '<h3><i class="fas fa-building"></i> ' + (currentCondominio?.nombre || '') + '</h3>';
        
        if (!data.inmuebles || data.inmuebles.length === 0) {
            container.innerHTML = '<div class="alert alert-info">No tienes inmuebles asignados</div>';
            return;
        }
        
        container.innerHTML = data.inmuebles.map(inm => {
            const deuda = inm.total_deuda > 0 ? '<div class="deuda-badge"><i class="fas fa-exclamation-triangle"></i> Deuda: $' + inm.total_deuda.toFixed(2) + '</div>' : '<div class="pagado-badge"><i class="fas fa-check-circle"></i> Al día</div>';
            return '<div class="property-card" onclick="showDeudas(' + inm.id + ', \'' + escapeHtml(inm.nombre) + '\')"><div class="property-info"><h3><i class="fas fa-door-open"></i> ' + escapeHtml(inm.nombre) + '</h3><p><i class="fas fa-money-bill-wave"></i> Ver deudas y pagos</p>' + deuda + '</div></div>';
        }).join('');
        
    } catch (err) {
        container.innerHTML = '<div class="alert alert-danger">Error: ' + err.message + '</div>';
    }
}

async function showDeudas(inmuebleId, nombreInmueble) {
    showView('deudas-view');
    currentInmueble = { id: parseInt(inmuebleId), nombre: nombreInmueble };
    
    const container = document.getElementById('deudas-content');
    
    try {
        const url = `${API_BASE}deudas.php?user_id=${user.id}&user_type=${user.tipo}&condominio_db=${currentCondominio?.db_name}&inmueble_id=${inmuebleId}`;
        const response = await fetch(url);
        const data = await response.json();
        
        if (data.error) {
            container.innerHTML = '<div class="alert alert-danger">' + data.error + '</div>';
            return;
        }
        
        currentDeudas = data.deudas || [];
        const resumen = data.resumen || {};
        
        container.innerHTML = '<div class="card"><h3><i class="fas fa-door-open"></i> ' + escapeHtml(nombreInmueble) + '</h3><div class="resumen-box"><div class="resumen-item"><span class="resumen-label">Total Gastos</span><span class="resumen-value danger">$' + (resumen.total_deuda?.toFixed(2) || '0.00') + '</span></div><div class="resumen-item"><span class="resumen-label">Meses</span><span class="resumen-value warning">' + (resumen.meses_pendientes || 0) + '</span></div></div><button class="btn btn-success" onclick="showPagarForm()"' + (resumen.total_deuda <= 0 ? ' disabled' : '') + '><i class="fas fa-credit-card"></i> Realizar Pago</button><button class="btn btn-primary" onclick="showGastosList()"><i class="fas fa-file-invoice-dollar"></i> Ver Gastos</button><button class="btn btn-info" onclick="showMisPagos()"><i class="fas fa-history"></i> Ver Pagos</button></div><h4><i class="fas fa-list"></i> Gastos por Mes</h4>';
        
        const debts = data.deudas || [];
        if (debts.length === 0) {
            container.innerHTML += '<p class="text-muted">No hay gastos registrados</p>';
        } else {
            container.innerHTML += debts.map(d => '<div class="deuda-item"><div class="deuda-header"><span class="deuda-mes">' + d.mes + ' ' + d.anio + '</span></div><div class="deuda-details"><div><span>Total:</span> <strong>$' + parseFloat(d.total_a_pagar_mes || 0).toFixed(2) + '</strong></div><div><span>Total:</span> <strong>$' + parseFloat(d.condominio || 0).toFixed(2) + '</div><div><span>Total:</span> <strong>$' + parseFloat(d.fondo_reserva || 0).toFixed(2) + '</div></div></div>').join('');
        }
        
    } catch (err) {
        container.innerHTML = '<div class="alert alert-danger">Error: ' + err.message + '</div>';
    }
}

function showPagarForm() {
    showView('pagar-view');
    
    const container = document.getElementById('pagar-content');
    const pendientes = currentDeudas.filter(d => d.estado !== 'pagado');
    const tieneSoloUnMes = pendientes.length === 1;
    
    container.innerHTML = '<div class="card"><h3><i class="fas fa-credit-card"></i> Realizar Pago</h3><p class="text-muted">Inmueble: ' + escapeHtml(currentInmueble?.nombre) + '</p><div id="pago-error" class="alert alert-danger hidden"></div><div id="pago-success" class="alert alert-success hidden"></div><form id="pago-form"><div class="form-group"><label>Tipo de Operación *</label><select id="tipo_operacion" required><option value="">Seleccionar...</option><option value="completo">Pago Completo</option><option value="abono">Abono Parcial</option>' + (!tieneSoloUnMes ? '<option value="ambos">Pago Completo con Abono</option>' : '') + '</select></div><div class="form-group" id="seccion_mes_completo"><label>Mes a Pagar (Completo) *</label><select id="mes_pago" required><option value="">Seleccionar...</option>' + pendientes.map(d => '<option value="' + d.mes + '-' + d.anio + '">' + getMonthName(d.mes) + ' ' + d.anio + ' - Pendiente: $' + parseFloat(d.total_a_pagar_mes || 0).toFixed(2) + '</option>').join('') + '</select></div><div class="form-group" id="seccion_mes_abono" style="display:none;"><label>Mes para Abono *</label><select id="mes_abono"><option value="">Seleccionar...</option>' + pendientes.map(d => '<option value="' + d.mes + '-' + d.anio + '">' + getMonthName(d.mes) + ' ' + d.anio + ' - Pendiente: $' + parseFloat(d.total_a_pagar_mes || 0).toFixed(2) + '</option>').join('') + '</select></div><div class="form-group" id="seccion_monto_abono" style="display:none;"><label>Monto de Abono ($) *</label><input type="number" id="monto_abono" step="0.01" min="0.01" placeholder="0.00"></div><div class="form-group"><label>Monto a Pagar ($) *</label><input type="number" id="monto_pagar" required step="0.01" min="0.01" placeholder="0.00"></div><div class="form-group"><label>Tipo de Pago *</label><select id="tipo_pago" required><option value="">Seleccionar...</option><option value="Efectivo">Efectivo (USD)</option><option value="Zelle">Zelle (USD)</option><option value="Transferencia">Transferencia (Bs.)</option><option value="Pago Movil">Pago Móvil (Bs.)</option><option value="Otros">Otros</option></select></div><div class="form-group" id="seccion_tasa_cambio" style="display:none;"><label>Tasa de Cambio (Bs/$) *</label><input type="number" id="tasa_cambio" step="0.0001" placeholder="0.00"></div><div class="form-group" id="seccion_monto_bs" style="display:none;"><label>Monto Bolivares</label><input type="number" id="monto_bs" step="0.01" placeholder="0.00" readonly></div><div class="form-group"><label>Banco *</label><input type="text" id="banco" required placeholder="Nombre del banco"></div><div class="form-group"><label>Número de Referencia *</label><input type="text" id="referencia" required placeholder="Número de referencia"></div><div class="form-group"><label>Comprobante de Pago</label><input type="file" id="comprobante" accept="image/*,.pdf"></div><button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Confirmar Pago</button></form></div>';
    
    document.getElementById('tipo_operacion').addEventListener('change', function() {
        const tipo = this.value;
        const seccionCompleto = document.getElementById('seccion_mes_completo');
        const seccionAbono = document.getElementById('seccion_mes_abono');
        const seccionMontoAbono = document.getElementById('seccion_monto_abono');
        const mesPago = document.getElementById('mes_pago');
        const montoPagar = document.getElementById('monto_pagar');
        
        seccionCompleto.style.display = 'none';
        seccionAbono.style.display = 'none';
        seccionMontoAbono.style.display = 'none';
        mesPago.required = false;
        montoPagar.required = true;
        
        if (tipo === 'completo') {
            seccionCompleto.style.display = 'block';
            mesPago.required = true;
        } else if (tipo === 'abono') {
            seccionAbono.style.display = 'block';
            seccionMontoAbono.style.display = 'block';
        } else if (tipo === 'ambos') {
            seccionCompleto.style.display = 'block';
            seccionAbono.style.display = 'block';
            seccionMontoAbono.style.display = 'block';
            mesPago.required = true;
        }
    });
    
    document.getElementById('tipo_pago').addEventListener('change', function() {
        const tipoPago = this.value;
        const seccionTasa = document.getElementById('seccion_tasa_cambio');
        const seccionMontoBs = document.getElementById('seccion_monto_bs');
        const tasaCambio = document.getElementById('tasa_cambio');
        const montoBs = document.getElementById('monto_bs');
        
        if (tipoPago === 'Transferencia' || tipoPago === 'Pago Movil') {
            seccionTasa.style.display = 'block';
            seccionMontoBs.style.display = 'block';
            tasaCambio.required = true;
        } else {
            seccionTasa.style.display = 'none';
            seccionMontoBs.style.display = 'none';
            tasaCambio.required = false;
            tasaCambio.value = '';
            montoBs.value = '';
        }
    });
    
    document.getElementById('tasa_cambio').addEventListener('input', function() {
        calcularMontoBs();
    });
    
    document.getElementById('tasa_cambio').addEventListener('change', function() {
        calcularMontoBs();
    });
    
    function calcularMontoBs() {
        var tipoPago = document.getElementById('tipo_pago').value;
        var tasaCambio = parseFloat(document.getElementById('tasa_cambio').value) || 0;
        var montoDolares = parseFloat(document.getElementById('monto_pagar').value) || 0;
        var montoBsElement = document.getElementById('monto_bs');
        
        if ((tipoPago === 'Transferencia' || tipoPago === 'Pago Movil') && montoBsElement) {
            if (tasaCambio > 0 && montoDolares > 0) {
                var bs = montoDolares * tasaCambio;
                montoBsElement.value = bs.toFixed(2);
            } else {
                montoBsElement.value = '0.00';
            }
        }
    }
    
    document.getElementById('monto_abono').addEventListener('input', function() {
        const tipoOperacion = document.getElementById('tipo_operacion').value;
        const montoAbono = parseFloat(this.value) || 0;
        
        if (tipoOperacion === 'abono') {
            document.getElementById('monto_pagar').value = montoAbono.toFixed(2);
        } else if (tipoOperacion === 'ambos') {
            const montoCompleto = parseFloat(document.getElementById('monto_pagar').getAttribute('data-monto-completo') || 0);
            const total = montoCompleto + montoAbono;
            document.getElementById('monto_pagar').value = total.toFixed(2);
        }
        calcularMontoBs();
    });
    
    document.getElementById('monto_pagar').addEventListener('input', function() {
        const tipoOperacion = document.getElementById('tipo_operacion').value;
        if (tipoOperacion === 'abono') {
            document.getElementById('monto_abono').value = this.value;
        }
        calcularMontoBs();
    });
    
    document.getElementById('mes_pago').addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const monto = parseFloat(selectedOption.textContent.match(/\$\d+\.\d+/)?.[0].replace('$', '') || 0);
        const tipoOperacion = document.getElementById('tipo_operacion').value;
        if (monto > 0 && (tipoOperacion === 'completo' || tipoOperacion === 'ambos')) {
            document.getElementById('monto_pagar').value = monto.toFixed(2);
            document.getElementById('monto_pagar').setAttribute('data-monto-completo', monto.toFixed(2));
            if (tipoOperacion === 'ambos') {
                document.getElementById('monto_abono').value = '0.00';
                document.getElementById('monto_abono').setAttribute('data-base-monto', monto.toFixed(2));
            }
        }
    });
    
    document.getElementById('mes_abono').addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const monto = parseFloat(selectedOption.textContent.match(/\$\d+\.\d+/)?.[0].replace('$', '') || 0);
        const tipoOperacion = document.getElementById('tipo_operacion').value;
        if (monto > 0 && tipoOperacion === 'abono') {
            document.getElementById('monto_abono').value = '0.00';
            document.getElementById('monto_pagar').value = monto.toFixed(2);
            document.getElementById('monto_pagar').setAttribute('data-monto-abono', monto.toFixed(2));
            calcularMontoBs();
        }
    });
    
    document.getElementById('pago-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        await procesarPago();
    });
}

async function procesarPago() {
    const tipoOperacion = document.getElementById('tipo_operacion').value;
    const mesPago = document.getElementById('mes_pago').value;
    const monto = document.getElementById('monto_pagar').value;
    const tipoPago = document.getElementById('tipo_pago').value;
    const tasaCambio = document.getElementById('tasa_cambio')?.value || '';
    const banco = document.getElementById('banco').value;
    const referencia = document.getElementById('referencia').value;
    const mesAbono = document.getElementById('mes_abono')?.value || '';
    const montoAbono = document.getElementById('monto_abono')?.value || '';
    const comprobante = document.getElementById('comprobante')?.files[0] || null;
    const montoBs = document.getElementById('monto_bs')?.value || '';
    
    if (!tipoOperacion || !monto || !tipoPago || !banco || !referencia) {
        document.getElementById('pago-error').textContent = 'Complete todos los campos';
        document.getElementById('pago-error').classList.remove('hidden');
        return;
    }
    
    if ((tipoOperacion === 'completo' || tipoOperacion === 'ambos') && !mesPago) {
        document.getElementById('pago-error').textContent = 'Seleccione el mes a pagar';
        document.getElementById('pago-error').classList.remove('hidden');
        return;
    }
    
    if ((tipoOperacion === 'abono' || tipoOperacion === 'ambos') && !mesAbono) {
        document.getElementById('pago-error').textContent = 'Seleccione el mes para abono';
        document.getElementById('pago-error').classList.remove('hidden');
        return;
    }
    
    const [mes, anio] = mesPago ? mesPago.split('-') : mesAbono.split('-');
    const deuda = currentDeudas.find(d => d.mes == mes && d.anio == anio);
    
    try {
        const formData = new FormData();
        formData.append('action', 'pagar');
        formData.append('user_id', user.id);
        formData.append('user_type', user.tipo);
        formData.append('condominio_db', currentCondominio?.db_name);
        formData.append('inmueble_id', currentInmueble?.id);
        formData.append('tipo_operacion', tipoOperacion);
        
        if (tipoOperacion === 'completo') {
            formData.append('monto_completo', monto);
            formData.append('mes_pago', getMonthName(mes));
            formData.append('anio_pago', anio);
        } else if (tipoOperacion === 'abono') {
            formData.append('monto_abono', montoAbono || monto);
            const [mesA, anioA] = mesAbono ? mesAbono.split('-') : [mes, anio];
            formData.append('mes_abono', getMonthName(mesA));
            formData.append('anio_abono', anioA);
        } else if (tipoOperacion === 'ambos') {
            const montoCompleto = parseFloat(document.getElementById('monto_pagar').getAttribute('data-monto-completo') || 0);
            formData.append('monto_completo', montoCompleto);
            formData.append('mes_pago', getMonthName(mes));
            formData.append('anio_pago', anio);
            formData.append('monto_abono', montoAbono);
            const [mesA, anioA] = mesAbono.split('-');
            formData.append('mes_abono', getMonthName(mesA));
            formData.append('anio_abono', anioA);
        }
        
        formData.append('monto_bs', montoBs);
        formData.append('tipo_pago', tipoPago);
        formData.append('tasa_cambio', tasaCambio);
        formData.append('banco', banco);
        formData.append('referencia', referencia);
        
        if (comprobante) {
            formData.append('comprobante', comprobante);
        }
        
        console.log('=== INICIANDO PROCESO DE PAGO ===');
        console.log('URL:', API_BASE + 'pagos.php');
        console.log('Datos a enviar:');
        for (let [key, value] of formData.entries()) {
            console.log('  ' + key + ':', value);
        }
        
        const response = await fetch(API_BASE + 'pagos.php', {
            method: 'POST',
            body: formData
        });
        console.log('Status de respuesta:', response.status, response.statusText);
        
        const data = await response.json();
        console.log('Respuesta del servidor:', data);
        
        if (data.success) {
            if (data.mostrar_sweetalert) {
                Swal.fire({
                    title: data.sweetalert_title || 'Transacción Realizada',
                    text: data.sweetalert_text || 'La transacción fue realizada satisfactoriamente.',
                    icon: data.sweetalert_icon || 'success',
                    confirmButtonText: 'Aceptar',
                    confirmButtonColor: '#28a745'
                }).then(() => {
                    document.getElementById('pago-form').reset();
                    showDeudas(currentInmueble.id, currentInmueble.nombre);
                });
            } else {
                document.getElementById('pago-success').innerHTML = '<i class="fas fa-check-circle"></i> ' + data.mensaje;
                document.getElementById('pago-success').classList.remove('hidden');
                document.getElementById('pago-form').reset();
                setTimeout(() => {
                    showDeudas(currentInmueble.id, currentInmueble.nombre);
                }, 2000);
            }
        } else {
            console.error('ERROR DEL SERVIDOR:', data.error, 'DEBUG:', data.debug);
            let errorHTML = data.error || 'Error al procesar pago';
            if (data.debug) {
                errorHTML += '<pre style="text-align:left; font-size:10px; margin-top:10px; background:#f8f9fa; padding:10px; border-radius:5px; max-height:300px; overflow:auto;">';
                errorHTML += '<strong>DEBUG:</strong>\n';
                errorHTML += JSON.stringify(data.debug, null, 2);
                errorHTML += '</pre>';
            }
            document.getElementById('pago-error').innerHTML = errorHTML;
            document.getElementById('pago-error').classList.remove('hidden');
        }
    } catch (err) {
        console.error('ERROR DE CONEXION:', err);
        document.getElementById('pago-error').textContent = 'Error de conexión: ' + err.message;
        document.getElementById('pago-error').classList.remove('hidden');
    }
    console.log('=== FIN PROCESO DE PAGO ===');
}

async function showGastosList() {
    showView('recibos-view');
    
    const container = document.getElementById('recibos-content');
    const inmuebleId = currentInmueble?.id || '';
    
    try {
        const url = `${API_BASE}gastos.php?user_id=${user.id}&user_type=${user.tipo}&condominio_db=${currentCondominio?.db_name}&inmueble_id=${inmuebleId}`;
        const response = await fetch(url);
        const data = await response.json();
        
        if (data.error) {
            container.innerHTML = '<div class="alert alert-danger">' + data.error + '</div>';
            return;
        }
        
        const gastos = data.gastos || [];
        
        container.innerHTML = '<div style="background: linear-gradient(135deg, #1e3a5f 0%, #2d5a87 100%); color: white; padding: 20px; border-radius: 12px; margin-bottom: 20px;"><div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;"><div><h2 style="margin: 0 0 5px 0; font-size: 20px;"><i class="fas fa-file-invoice-dollar"></i> Gastos por Inmueble</h2><p style="margin: 0; opacity: 0.8; font-size: 14px;">' + (currentInmueble?.nombre || 'Todos los Inmuebles') + '</p></div><div style="text-align: right;"><div style="font-size: 12px; opacity: 0.8;">Total Registros</div><div style="font-size: 28px; font-weight: bold;">' + gastos.length + '</div></div></div></div><div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 15px;">';
        
        if (gastos.length === 0) {
            container.innerHTML += '<div class="alert alert-info" style="grid-column: 1/-1;">No hay gastos registrados</div>';
        } else {
            container.innerHTML += gastos.map(g => '<div style="background: white; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); overflow: hidden; cursor: pointer;"><div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center;"><div><div style="font-size: 18px; font-weight: bold;">' + g.mes + ' ' + g.anio + '</div><div style="font-size: 12px; opacity: 0.9;"><i class="fas fa-building"></i> ' + (g.inmueble_nombre || 'Inmueble ' + g.inmueble_id) + '</div></div><div style="background: rgba(255,255,255,0.2); padding: 8px 12px; border-radius: 8px;"><i class="fas fa-receipt" style="font-size: 20px;"></i></div></div><div style="padding: 20px; text-align: center;"><div style="font-size: 14px; color: #666; margin-bottom: 5px;">TOTAL A PAGAR</div><div style="font-size: 28px; font-weight: bold; color: #28a745;">$' + parseFloat(g.total_a_pagar_mes || 0).toFixed(2) + '</div></div><div style="padding: 0 15px 15px 15px;"><button class="btn btn-primary" style="width: 100%; padding: 12px; border-radius: 8px; border: none; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); font-weight: 600; font-size: 14px;" onclick="event.stopPropagation();verGasto(' + g.id + ')"><i class="fas fa-file-pdf"></i> Ver Recibo</button></div></div></div>').join('');
        }
        
        container.innerHTML += '</div>';
        
    } catch (err) {
        container.innerHTML = '<div class="alert alert-danger">Error: ' + err.message + '</div>';
    }
}

function verGasto(gastoId) {
    const volverFn = 'showGastosList';
    const token = btoa(user.id + ':' + currentCondominio?.db_name + ':' + volverFn);
    const url = 'https://gerencia.inversionesares2.com/condominios_app/www/ver_gasto.php?id=' + gastoId + '&token=' + token;
    window.location.href = url;
}

async function showMisPagos() {
    showView('pagos-view');
    
    const container = document.getElementById('pagos-content');
    const inmuebleId = currentInmueble?.id || '';
    
    container.innerHTML = '<div class="text-center"><i class="fas fa-spinner fa-spin"></i> Cargando pagos...</div>';
    
    try {
        const url = `${API_BASE}mis_pagos.php?user_id=${user.id}&user_type=${user.tipo}&condominio_db=${currentCondominio?.db_name}&inmueble_id=${inmuebleId}`;
        const response = await fetch(url);
        const data = await response.json();
        
        if (data.error) {
            container.innerHTML = '<div class="alert alert-danger">' + data.error + '</div>';
            return;
        }
        
        const pagosAprobados = data.pagos_aprobados || [];
        const pagosPendientes = data.pagos_pendientes || [];
        
        if (pagosAprobados.length === 0 && pagosPendientes.length === 0) {
            container.innerHTML = '<div class="card"><h3><i class="fas fa-history"></i> Mis Pagos</h3><p class="text-muted">No tienes pagos registrados.</p></div>';
            return;
        }
        
        let html = '<div class="card"><h3><i class="fas fa-history"></i> Mis Pagos</h3><p class="text-muted">Historial de pagos del inmueble: ' + escapeHtml(currentInmueble?.nombre) + '</p></div>';
        
        if (pagosPendientes.length > 0) {
            html += '<h4 style="color: #ffc107; margin-top: 20px;"><i class="fas fa-clock"></i> Pagos Pendientes de Verificación</h4>';
            html += pagosPendientes.map(pago => {
                const esAbono = pago.es_abono == 1 || pago.es_abono === true;
                const tipoLabel = esAbono ? 'Abono Parcial' : 'Pago Completo';
                const montoMostrar = parseFloat(pago.total_pagar || pago.monto_mes || 0).toFixed(2);
                const fechaFormateada = new Date(pago.fecha_pago).toLocaleDateString('es-VE');
                return '<div class="card" style="margin-bottom: 15px; border-left: 4px solid #ffc107;"><div style="display: flex; justify-content: space-between; align-items: center; margin-bottom:10px;"><span class="badge bg-warning" style="font-size: 12px; color: #000;"><i class="fas fa-clock"></i> Pendiente de Verificación</span><span class="badge bg-secondary" style="font-size: 11px; margin-left:5px;">' + tipoLabel + '</span></div><div style="font-size: 12px; color: #666;">' + fechaFormateada + '</div><div style="font-size: 14px; margin: 10px 0;"><strong>Mes:</strong> ' + (pago.mes_pago_display || pago.mes_pago || 'N/A') + '</div><div style="font-size: 16px; font-weight: bold; color: #28a745;">$' + montoMostrar + '</div></div>';
            }).join('');
        }
        
        if (pagosAprobados.length > 0) {
            html += '<h4 style="color: #28a745; margin-top: 20px;"><i class="fas fa-check-circle"></i> Pagos Aprobados</h4>';
            html += pagosAprobados.map(pago => {
                const esAbono = pago.es_abono == 1 || pago.es_abono === true;
                const tipoLabel = esAbono ? 'Abono Parcial' : 'Pago Completo';
                const montoMostrar = parseFloat(pago.total_pagar || pago.monto_mes || 0).toFixed(2);
                const fechaFormateada = new Date(pago.fecha_pago).toLocaleDateString('es-VE');
                return '<div class="card" style="margin-bottom: 15px; border-left: 4px solid #28a745;"><div style="display: flex; justify-content: space-between; align-items: center; margin-bottom:10px;"><span class="badge bg-success" style="font-size: 12px;"><i class="fas fa-check-circle"></i> Aprobado</span><span class="badge bg-secondary" style="font-size: 11px; margin-left:5px;">' + tipoLabel + '</span></div><div style="font-size: 12px; color: #666;">' + fechaFormateada + '</div><div style="font-size: 14px; margin: 10px 0;"><strong>Mes:</strong> ' + (pago.mes_pago_display || pago.mes_pago || 'N/A') + '</div><div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 13px;"><div><strong>Monto:</strong></div><div style="text-align: right; font-weight: bold; color: #28a745;">$' + montoMostrar + '</div><div><strong>Método:</strong></div><div style="text-align: right;">' + (pago.tipo_pago || 'N/A') + '</div></div><button class="btn btn-primary btn-sm" style="margin-top: 15px;" onclick="verRecibo(' + pago.id_pago + ')"><i class="fas fa-file-pdf"></i> Ver Recibo</button></div>';
            }).join('');
        }
        
        container.innerHTML = html;
        
    } catch (err) {
        container.innerHTML = '<div class="alert alert-danger">Error: ' + err.message + '</div>';
    }
}

function verRecibo(pagoId) {
    sessionStorage.setItem('recibos_volver_fn', 'showGastosList');
    const token = btoa(user.id + ':' + currentCondominio?.db_name);
    const url = 'https://gerencia.inversionesares2.com/condominios_app/www/ver_recibo.php?id=' + pagoId + '&token=' + token;
    window.location.href = url;
}

function showAdmin() {
    showView('admin-view');
    document.getElementById('admin-content').innerHTML = '<div class="card"><h3>Panel de Administración</h3><p>Bienvenido, ' + escapeHtml(user.nombre) + '</p><pTtipo: ' + user.tipo + '</p></div>';
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
}

function getMonthName(monthNum) {
    const meses = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    if (isNaN(monthNum)) return monthNum;
    return meses[parseInt(monthNum)] || monthNum;
}

function formatDate(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    return d.toLocaleDateString('es-ES');
}

async function showPerfil() {
    showView('perfil-view');
    
    const container = document.getElementById('perfil-content');
    container.innerHTML = '<div class="text-center"><i class="fas fa-spinner fa-spin"></i> Cargando perfil...</div>';
    
    const dbName = currentCondominio?.db_name;
    
    if (!dbName) {
        container.innerHTML = '<div class="alert alert-danger">Error: No hay condominio asociado</div>';
        return;
    }
    
    try {
        const url = API_BASE + 'perfil.php?action=get_perfil&user_id=' + user.id + '&condominio_db=' + dbName;
        const response = await fetch(url);
        const data = await response.json();
        
        if (!data.success) {
            container.innerHTML = '<div class="alert alert-danger">' + data.error + '</div>';
            return;
        }
        
        const locatario = data.locatario || {};
        
        container.innerHTML = '<div class="card"><h2><i class="fas fa-user-circle"></i> Mi Perfil</h2><div id="perfil-error" class="alert alert-danger hidden"></div><div id="perfil-success" class="alert alert-success hidden"></div><form id="perfil-form"><div class="form-group"><label><i class="fas fa-user"></i> Nombre</label><input type="text" id="perfil-nombre" value="' + escapeHtml(locatario.nombre || '') + '" required></div><div class="form-group"><label><i class="fas fa-user"></i> Apellido</label><input type="text" id="perfil-apellido" value="' + escapeHtml(locatario.apellido || '') + '" required></div><div class="form-group"><label><i class="fas fa-id-card"></i> Identificación</label><input type="text" id="perfil-identificacion" value="' + escapeHtml(locatario.identificacion || '') + '" required></div><div class="form-group"><label><i class="fas fa-envelope"></i> Email</label><input type="email" id="perfil-email" value="' + escapeHtml(locatario.email || '') + '" required></div><div class="form-group"><label><i class="fas fa-phone"></i> Teléfono</label><input type="tel" id="perfil-telefono" value="' + escapeHtml(locatario.telefono || '') + '" required></div><div class="form-group"><label><i class="fas fa-lock"></i> Contraseña Actual</label><div class="password-input-container"><input type="password" id="perfil-password-actual" value="' + escapeHtml(locatario.password || '') + '" readonly style="background: #f0f0f0; color: #666;"><span class="toggle-password" onclick="togglePasswordVisibility(\'perfil-password-actual\')"><i class="fas fa-eye"></i></span></div></div><div class="form-group"><label><i class="fas fa-lock"></i> Nueva Contraseña</label><div class="password-input-container"><input type="password" id="perfil-password" placeholder="Nueva contraseña (dejar vacío para mantener actual)"><span class="toggle-password" onclick="togglePasswordVisibility(\'perfil-password\')"><i class="fas fa-eye"></i></span></div><div class="password-strength"><div class="strength-bar"><div id="strength-bar" class="strength-fill"></div></div><span id="strength-text" class="strength-text"></span></div></div><div class="form-group"><label><i class="fas fa-lock"></i> Confirmar Nueva Contraseña</label><div class="password-input-container"><input type="password" id="perfil-password-confirm" placeholder="Confirmar nueva contraseña"><span class="toggle-password" onclick="togglePasswordVisibility(\'perfil-password-confirm\')"><i class="fas fa-eye"></i></span></div><div class="password-match"><i id="match-icon" class="fas"></i><span id="match-text"></span></div></div><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar Cambios</button></form></div>';
        
        const passwordInput = document.getElementById('perfil-password');
        const confirmInput = document.getElementById('perfil-password-confirm');
        const matchIcon = document.getElementById('match-icon');
        const matchText = document.getElementById('match-text');
        const strengthBar = document.getElementById('strength-bar');
        const strengthText = document.getElementById('strength-text');
        
        passwordInput.addEventListener('input', function() {
            updatePasswordStrength(this.value);
            checkPasswordMatch();
        });
        
        confirmInput.addEventListener('input', checkPasswordMatch);
        
        function updatePasswordStrength(password) {
            let strength = 0;
            let text = '';
            let color = '';
            
            if (password.length > 0) {
                if (password.length >= 6) strength += 1;
                if (password.length >= 8) strength += 1;
                if (/[A-Z]/.test(password)) strength += 1;
                if (/[0-9]/.test(password)) strength += 1;
                if (/[^A-Za-z0-9]/.test(password)) strength += 1;
            }
            
            switch(strength) {
                case 0:
                    text = '';
                    color = '';
                    break;
                case 1:
                    text = 'Débil';
                    color = '#dc3545';
                    break;
                case 2:
                    text = 'Media';
                    color = '#ffc107';
                    break;
                case 3:
                    text = 'Buena';
                    color = '#28a745';
                    break;
                case 4:
                case 5:
                    text = 'Fuerte';
                    color = '#20c997';
                    break;
            }
            
            strengthBar.style.width = (strength * 20) + '%';
            strengthBar.style.backgroundColor = color;
            strengthText.textContent = text;
            strengthText.style.color = color;
        }
        
        function checkPasswordMatch() {
            const password = passwordInput.value;
            const confirm = confirmInput.value;
            
            if (confirm.length === 0) {
                matchIcon.className = 'fas';
                matchText.textContent = '';
                return;
            }
            
            if (password === confirm) {
                matchIcon.className = 'fas fa-check-circle';
                matchIcon.style.color = '#28a745';
                matchText.textContent = 'Las contraseñas coinciden';
                matchText.style.color = '#28a745';
            } else {
                matchIcon.className = 'fas fa-times-circle';
                matchIcon.style.color = '#dc3545';
                matchText.textContent = 'Las contraseñas no coinciden';
                matchText.style.color = '#dc3545';
            }
        }
        
        document.getElementById('perfil-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const nombre = document.getElementById('perfil-nombre').value;
            const apellido = document.getElementById('perfil-apellido').value;
            const identificacion = document.getElementById('perfil-identificacion').value;
            const email = document.getElementById('perfil-email').value;
            const telefono = document.getElementById('perfil-telefono').value;
            const password = document.getElementById('perfil-password').value;
            const passwordConfirm = document.getElementById('perfil-password-confirm').value;
            
            if (password && password !== passwordConfirm) {
                document.getElementById('perfil-error').textContent = 'Las contraseñas no coinciden';
                document.getElementById('perfil-error').classList.remove('hidden');
                document.getElementById('perfil-success').classList.add('hidden');
                return;
            }
            
            if (password && password.length < 4) {
                document.getElementById('perfil-error').textContent = 'La contraseña debe tener al menos 4 caracteres';
                document.getElementById('perfil-error').classList.remove('hidden');
                document.getElementById('perfil-success').classList.add('hidden');
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('action', 'actualizar_perfil');
                formData.append('user_id', user.id);
                formData.append('condominio_db', dbName);
                formData.append('nombre', nombre);
                formData.append('apellido', apellido);
                formData.append('identificacion', identificacion);
                formData.append('email', email);
                formData.append('telefono', telefono);
                if (password) {
                    formData.append('password', password);
                    formData.append('password_usuario', password);
                }
                
                const response = await fetch(API_BASE + 'perfil.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                
                if (data.success) {
                    document.getElementById('perfil-success').innerHTML = '<i class="fas fa-check"></i> ' + data.mensaje;
                    document.getElementById('perfil-success').classList.remove('hidden');
                    document.getElementById('perfil-error').classList.add('hidden');
                    
                    document.getElementById('perfil-password').value = '';
                    document.getElementById('perfil-password-confirm').value = '';
                    
                    user.nombre = nombre + ' ' + apellido;
                    localStorage.setItem('user', JSON.stringify(user));
                    
                    showPerfil();
                } else {
                    document.getElementById('perfil-error').textContent = data.error || 'Error al guardar';
                    document.getElementById('perfil-error').classList.remove('hidden');
                    document.getElementById('perfil-success').classList.add('hidden');
                }
            } catch (err) {
                document.getElementById('perfil-error').textContent = 'Error de conexión: ' + err.message;
                document.getElementById('perfil-error').classList.remove('hidden');
            }
        });
    } catch (err) {
        container.innerHTML = '<div class="alert alert-danger">Error: ' + err.message + '</div>';
    }
}
