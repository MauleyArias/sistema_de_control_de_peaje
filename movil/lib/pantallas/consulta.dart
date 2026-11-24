import 'dart:io';

import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import '../api.dart';

/// Modo Consulta: chat con el agente, por texto o con foto de la placa.
class ModoConsulta extends StatefulWidget {
  const ModoConsulta({super.key});

  @override
  State<ModoConsulta> createState() => _ModoConsultaState();
}

class _Mensaje {
  _Mensaje(this.texto, this.mio, {this.imagen, this.estado});
  final String texto;
  final bool mio;
  final File? imagen;
  final String? estado;
}

class _ModoConsultaState extends State<ModoConsulta> {
  final _texto = TextEditingController();
  final _scroll = ScrollController();
  final _mensajes = <_Mensaje>[_Mensaje('Hola, soy el agente del peaje. Escribe una placa o envía una foto.', false)];
  final _historial = <Map<String, String>>[];
  bool _enviando = false;

  static const _etiquetas = {
    'registrada_con_paso': 'Registrada y con paso',
    'registrada_sin_paso': 'Registrada sin paso',
    'identificada_no_registrada': 'Identificada no registrada',
    'no_existe': 'No existe',
  };

  Future<void> _enviar({File? imagen}) async {
    final texto = _texto.text.trim();
    if (texto.isEmpty && imagen == null) return;
    setState(() {
      _mensajes.add(_Mensaje(texto.isEmpty ? '📷 Foto de placa' : texto, true, imagen: imagen));
      _enviando = true;
      _texto.clear();
    });
    _bajar();
    try {
      final r = await Api.i.chat(mensaje: texto, imagen: imagen, historial: _historial.length > 6 ? _historial.sublist(_historial.length - 6) : _historial);
      final lectura = r['lectura_imagen'] as Map<String, dynamic>?;
      var respuesta = r['respuesta']?.toString() ?? '';
      if (lectura != null && lectura['placa'] != null) {
        respuesta += '\n(OCR: ${lectura['placa']}, ${(((lectura['confianza'] ?? 0) as num) * 100).round()}%)';
      }
      _mensajes.add(_Mensaje(respuesta, false, estado: r['estado'] as String?));
      _historial
        ..add({'rol': 'user', 'texto': texto.isEmpty ? '[imagen]' : texto})
        ..add({'rol': 'model', 'texto': r['respuesta']?.toString() ?? ''});
    } catch (e) {
      _mensajes.add(_Mensaje('Error: $e', false));
    }
    setState(() => _enviando = false);
    _bajar();
  }

  Future<void> _foto(ImageSource fuente) async {
    final f = await ImagePicker().pickImage(source: fuente, maxWidth: 1600, imageQuality: 85);
    if (f != null) await _enviar(imagen: File(f.path));
  }

  void _bajar() => WidgetsBinding.instance.addPostFrameCallback((_) {
        if (_scroll.hasClients) _scroll.animateTo(_scroll.position.maxScrollExtent, duration: const Duration(milliseconds: 250), curve: Curves.easeOut);
      });

  @override
  Widget build(BuildContext context) {
    final esquema = Theme.of(context).colorScheme;
    return Column(children: [
      Expanded(
        child: ListView.builder(
          controller: _scroll,
          padding: const EdgeInsets.all(12),
          itemCount: _mensajes.length,
          itemBuilder: (_, n) {
            final m = _mensajes[n];
            return Align(
              alignment: m.mio ? Alignment.centerRight : Alignment.centerLeft,
              child: Container(
                margin: const EdgeInsets.symmetric(vertical: 4),
                padding: const EdgeInsets.all(12),
                constraints: BoxConstraints(maxWidth: MediaQuery.of(context).size.width * .8),
                decoration: BoxDecoration(color: m.mio ? esquema.primary : esquema.surfaceContainerHighest, borderRadius: BorderRadius.circular(14)),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  if (m.imagen != null) Padding(padding: const EdgeInsets.only(bottom: 6), child: ClipRRect(borderRadius: BorderRadius.circular(8), child: Image.file(m.imagen!, height: 140))),
                  Text(m.texto, style: TextStyle(color: m.mio ? esquema.onPrimary : null)),
                  if (m.estado != null)
                    Padding(padding: const EdgeInsets.only(top: 6), child: Chip(label: Text(_etiquetas[m.estado] ?? m.estado!), visualDensity: VisualDensity.compact)),
                ]),
              ),
            );
          },
        ),
      ),
      if (_enviando) const LinearProgressIndicator(),
      SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(8, 4, 8, 8),
          child: Row(children: [
            IconButton(onPressed: _enviando ? null : () => _foto(ImageSource.camera), icon: const Icon(Icons.photo_camera), tooltip: 'Foto'),
            IconButton(onPressed: _enviando ? null : () => _foto(ImageSource.gallery), icon: const Icon(Icons.photo_library), tooltip: 'Galería'),
            Expanded(
              child: TextField(
                controller: _texto,
                textCapitalization: TextCapitalization.characters,
                decoration: const InputDecoration(hintText: 'Placa, ej. 2580-BOS', border: OutlineInputBorder(), isDense: true),
                onSubmitted: (_) => _enviar(),
              ),
            ),
            IconButton(onPressed: _enviando ? null : _enviar, icon: const Icon(Icons.send)),
          ]),
        ),
      ),
    ]);
  }
}
