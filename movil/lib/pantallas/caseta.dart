import 'dart:async';
import 'dart:io';

import 'package:camera/camera.dart';
import 'package:flutter/material.dart';

import '../api.dart';
import '../arduino.dart';
import '../config.dart';

/// Modo Caseta: muestra el video de la cámara y envía frames (Config.fps por segundo) a /pasos/frame.
class ModoCaseta extends StatefulWidget {
  const ModoCaseta({super.key, required this.activa});
  final bool activa;

  @override
  State<ModoCaseta> createState() => _ModoCasetaState();
}

class _ModoCasetaState extends State<ModoCaseta> with WidgetsBindingObserver {
  CameraController? _camara;
  List<dynamic> _casetas = [];
  int? _casetaId;
  bool _capturando = false;
  bool _enVuelo = false;
  Timer? _temporizador;
  Map<String, dynamic>? _ultimo;
  Map<String, dynamic>? _ultimoRegistrado;
  String? _error;
  int _frames = 0;
  int? _latencia;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _iniciar();
  }

  Future<void> _iniciar() async {
    try {
      _casetas = await Api.i.casetas();
      _casetaId = _casetas.isNotEmpty ? _casetas.first['id'] as int : null;
    } catch (e) {
      _error = 'No se pudo cargar las casetas: $e';
    }
    await _abrirCamara();
  }

  Future<void> _abrirCamara() async {
    try {
      final camaras = await availableCameras();
      final trasera = camaras.firstWhere((c) => c.lensDirection == CameraLensDirection.back, orElse: () => camaras.first);
      final c = CameraController(trasera, ResolutionPreset.high, enableAudio: false, imageFormatGroup: ImageFormatGroup.jpeg);
      await c.initialize();
      await c.setFlashMode(FlashMode.off);
      if (!mounted) return;
      setState(() => _camara = c);
    } catch (e) {
      setState(() => _error = 'Cámara no disponible: $e');
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.inactive || state == AppLifecycleState.paused) {
      _detener();
      _camara?.dispose();
      _camara = null;
    } else if (state == AppLifecycleState.resumed && _camara == null) {
      _abrirCamara();
    }
  }

  void _alternar() => _capturando ? _detener() : _empezar();

  void _empezar() {
    if (_camara == null || _casetaId == null) return;
    setState(() {
      _capturando = true;
      _error = null;
    });
    _temporizador = Timer.periodic(Duration(milliseconds: 1000 ~/ Config.fps), (_) => _tick());
  }

  void _detener() {
    _temporizador?.cancel();
    _temporizador = null;
    if (mounted) setState(() => _capturando = false);
  }

  /// Un frame a la vez: si la petición anterior sigue en curso, se salta este tick (no se acumula retraso).
  Future<void> _tick() async {
    final c = _camara;
    if (_enVuelo || c == null || !c.value.isInitialized || c.value.isTakingPicture) return;
    _enVuelo = true;
    final inicio = DateTime.now();
    try {
      final foto = await c.takePicture();
      Arduino.i.enviar('P');
      final r = await Api.i.enviarFrame(File(foto.path), _casetaId!);
      File(foto.path).delete().ignore();
      _frames++;
      _latencia = DateTime.now().difference(inicio).inMilliseconds;
      await Arduino.i.senalizar(r);
      if (!mounted) return;
      setState(() {
        _ultimo = r;
        if (r['registrado'] == true) _ultimoRegistrado = r;
        _error = null;
      });
    } catch (e) {
      if (mounted) setState(() => _error = e.toString());
    } finally {
      _enVuelo = false;
    }
  }

  @override
  void didUpdateWidget(covariant ModoCaseta oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!widget.activa && _capturando) _detener();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _temporizador?.cancel();
    _camara?.dispose();
    super.dispose();
  }

  Color _color(String? estado) => switch (estado) {
        'cobrado' => Colors.green.shade700,
        'sin_saldo' || 'vehiculo_inactivo' => Colors.orange.shade800,
        'no_registrada' => Colors.red.shade700,
        _ => Colors.grey.shade700,
      };

  @override
  Widget build(BuildContext context) {
    final c = _camara;
    final r = _ultimoRegistrado ?? _ultimo;
    return Column(children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(12, 8, 12, 0),
        child: Row(children: [
          const Text('Caseta: '),
          Expanded(
            child: DropdownButton<int>(
              isExpanded: true,
              value: _casetaId,
              items: [for (final k in _casetas) DropdownMenuItem(value: k['id'] as int, child: Text('${k['nombre']}'))],
              onChanged: _capturando ? null : (v) => setState(() => _casetaId = v),
            ),
          ),
        ]),
      ),
      Expanded(
        child: Stack(fit: StackFit.expand, children: [
          if (c != null && c.value.isInitialized)
            ClipRect(child: FittedBox(fit: BoxFit.cover, child: SizedBox(width: c.value.previewSize!.height, height: c.value.previewSize!.width, child: CameraPreview(c))))
          else
            const Center(child: CircularProgressIndicator()),
          Positioned(
            top: 8,
            left: 8,
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
              decoration: BoxDecoration(color: _capturando ? Colors.red : Colors.black54, borderRadius: BorderRadius.circular(20)),
              child: Text(_capturando ? '● EN VIVO · ${Config.fps} fps · $_frames frames${_latencia != null ? ' · ${_latencia}ms' : ''}' : 'En pausa',
                  style: const TextStyle(color: Colors.white, fontSize: 12)),
            ),
          ),
          Positioned(
            top: 8,
            right: 8,
            child: ValueListenableBuilder(
              valueListenable: Arduino.i.estado,
              builder: (_, String e, _) => GestureDetector(
                onTap: Arduino.i.conectar,
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                  decoration: BoxDecoration(color: Colors.black54, borderRadius: BorderRadius.circular(20)),
                  child: Text(e, style: const TextStyle(color: Colors.white, fontSize: 11)),
                ),
              ),
            ),
          ),
        ]),
      ),
      Container(
        width: double.infinity,
        color: Theme.of(context).colorScheme.surfaceContainerHighest,
        padding: const EdgeInsets.all(12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          if (r == null)
            const Text('Pulsa Iniciar para capturar y enviar frames.')
          else ...[
            Row(children: [
              Text(r['placa'] ?? '— sin placa —', style: const TextStyle(fontSize: 26, fontWeight: FontWeight.bold, letterSpacing: 2, fontFamily: 'monospace')),
              const Spacer(),
              if (r['estado'] != null)
                Chip(label: Text('${r['estado']}'.replaceAll('_', ' '), style: const TextStyle(color: Colors.white)), backgroundColor: _color(r['estado'] as String?)),
            ]),
            Wrap(spacing: 16, runSpacing: 4, children: [
              Text('Categoría: ${r['categoria'] ?? '-'}'),
              Text('Ejes: ${r['ejes'] ?? '-'}'),
              Text('Pasajeros: ${r['pasajeros'] ?? '-'}'),
              Text('Tarifa: Bs ${r['tarifa'] ?? '-'}'),
              Text('Confianza: ${r['confianza'] == null ? '-' : ((r['confianza'] as num) * 100).round()}%'),
            ]),
            if (r['alerta'] != null) Text('⚠ Alerta: ${r['alerta']}', style: TextStyle(color: Colors.red.shade700, fontWeight: FontWeight.bold)),
            if (_ultimo != null && _ultimo!['registrado'] != true)
              Text('Último frame: ${_ultimo!['duplicado'] == true ? 'mismo vehículo (ya registrado)' : (_ultimo!['motivo'] ?? '')}', style: Theme.of(context).textTheme.bodySmall),
          ],
          if (_error != null) Text(_error!, style: TextStyle(color: Colors.red.shade700)),
          const SizedBox(height: 8),
          SizedBox(
            width: double.infinity,
            child: FilledButton.icon(
              onPressed: c == null || _casetaId == null ? null : _alternar,
              style: FilledButton.styleFrom(backgroundColor: _capturando ? Colors.red : null),
              icon: Icon(_capturando ? Icons.stop : Icons.play_arrow),
              label: Text(_capturando ? 'Detener' : 'Iniciar captura'),
            ),
          ),
        ]),
      ),
    ]);
  }
}
