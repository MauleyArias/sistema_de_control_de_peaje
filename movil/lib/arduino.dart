import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:usb_serial/usb_serial.dart';

/// Dispositivo de alerta (punto adicional): Arduino UNO conectado al celular de la caseta por cable OTG.
/// Protocolo de 1 byte (ver hardware/alerta_peaje/alerta_peaje.ino):
///   'P' procesando/inferencia  'V' paso cobrado (verde)  'A' alerta (rojo + buzzer)
///   'N' sin placa legible      'C' conectado (saludo)
class Arduino {
  Arduino._();
  static final Arduino i = Arduino._();

  UsbPort? _puerto;
  final estado = ValueNotifier<String>('Arduino: no conectado');
  StreamSubscription<UsbEvent>? _eventos;

  bool get conectado => _puerto != null;

  void escucharConexiones() {
    _eventos ??= UsbSerial.usbEventStream?.listen((e) {
      if (e.event == UsbEvent.ACTION_USB_ATTACHED) conectar();
      if (e.event == UsbEvent.ACTION_USB_DETACHED) _desconectado();
    });
  }

  Future<void> conectar() async {
    try {
      final dispositivos = await UsbSerial.listDevices();
      if (dispositivos.isEmpty) {
        estado.value = 'Arduino: no detectado (conecta el cable OTG)';
        return;
      }
      final puerto = await dispositivos.first.create();
      if (puerto == null || !await puerto.open()) {
        estado.value = 'Arduino: permiso USB denegado';
        return;
      }
      await puerto.setDTR(true);
      await puerto.setRTS(true);
      await puerto.setPortParameters(9600, UsbPort.DATABITS_8, UsbPort.STOPBITS_1, UsbPort.PARITY_NONE);
      _puerto = puerto;
      estado.value = 'Arduino: conectado (${dispositivos.first.productName ?? 'USB'})';
      // El UNO se reinicia al abrir el puerto: esperar al bootloader antes de saludar.
      Future.delayed(const Duration(seconds: 2), () => enviar('C'));
    } catch (e) {
      estado.value = 'Arduino: error $e';
    }
  }

  void _desconectado() {
    _puerto = null;
    estado.value = 'Arduino: desconectado';
  }

  Future<void> enviar(String comando) async {
    final p = _puerto;
    if (p == null) return;
    try {
      await p.write(Uint8List.fromList(comando.codeUnits));
    } catch (_) {
      _desconectado();
    }
  }

  /// Traduce el resultado de /pasos/frame a una señal del dispositivo.
  Future<void> senalizar(Map<String, dynamic> r) async {
    if (r['alerta'] != null || r['estado'] == 'no_registrada' || r['estado'] == 'sin_saldo' || r['estado'] == 'vehiculo_inactivo') {
      await enviar('A');
    } else if (r['estado'] == 'cobrado') {
      await enviar('V');
    } else if (r['registrado'] != true && r['duplicado'] != true) {
      await enviar('N');
    }
  }
}
