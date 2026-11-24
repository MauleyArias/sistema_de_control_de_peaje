import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

import 'config.dart';

class ApiError implements Exception {
  ApiError(this.mensaje, [this.status]);
  final String mensaje;
  final int? status;
  @override
  String toString() => mensaje;
}

/// Cliente de la API REST del peaje. Guarda el token del sistema emitido tras Google Sign-In.
class Api {
  Api._();
  static final Api i = Api._();

  String? token;
  Map<String, dynamic>? usuario;

  Uri _url(String ruta) => Uri.parse('${Config.apiUrl}/api/v1$ruta');

  Map<String, String> get _cabeceras => {
        'Accept': 'application/json',
        if (token != null) 'Authorization': 'Bearer $token',
      };

  Future<bool> cargarSesion() async {
    final prefs = await SharedPreferences.getInstance();
    token = prefs.getString('token');
    final u = prefs.getString('usuario');
    usuario = u == null ? null : jsonDecode(u) as Map<String, dynamic>;
    if (token == null) return false;
    try {
      usuario = await _get('/yo') as Map<String, dynamic>;
      return true;
    } catch (_) {
      await cerrarSesion();
      return false;
    }
  }

  /// POST /auth/google: canjea el ID token de Google por el token del sistema (lista blanca en el servidor).
  Future<void> loginGoogle(String idToken) async {
    final r = await http
        .post(_url('/auth/google'),
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
            body: jsonEncode({'id_token': idToken, 'dispositivo': 'app-movil-${Platform.operatingSystem}'}))
        .timeout(const Duration(seconds: 60));
    final j = _decodificar(r);
    token = j['token'] as String;
    usuario = j['usuario'] as Map<String, dynamic>;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString('token', token!);
    await prefs.setString('usuario', jsonEncode(usuario));
  }

  Future<void> cerrarSesion() async {
    token = null;
    usuario = null;
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove('token');
    await prefs.remove('usuario');
  }

  Future<List<dynamic>> casetas() async => await _get('/casetas') as List<dynamic>;

  Future<Map<String, dynamic>> resumen(int casetaId) async => await _get('/casetas/$casetaId/resumen') as Map<String, dynamic>;

  /// POST /pasos/frame
  Future<Map<String, dynamic>> enviarFrame(File imagen, int casetaId) async {
    final req = http.MultipartRequest('POST', _url('/pasos/frame'))
      ..headers.addAll(_cabeceras)
      ..fields['caseta_id'] = '$casetaId'
      ..files.add(await http.MultipartFile.fromPath('imagen', imagen.path, filename: 'frame.jpg'));
    final r = await http.Response.fromStream(await req.send().timeout(const Duration(seconds: 60)));
    return _decodificar(r) as Map<String, dynamic>;
  }

  /// POST /agente/chat (texto y/o imagen)
  Future<Map<String, dynamic>> chat({String? mensaje, File? imagen, List<Map<String, String>> historial = const []}) async {
    final req = http.MultipartRequest('POST', _url('/agente/chat'))..headers.addAll(_cabeceras);
    if (mensaje != null && mensaje.isNotEmpty) req.fields['mensaje'] = mensaje;
    for (var n = 0; n < historial.length; n++) {
      req.fields['historial[$n][rol]'] = historial[n]['rol']!;
      req.fields['historial[$n][texto]'] = historial[n]['texto']!;
    }
    if (imagen != null) {
      req.files.add(await http.MultipartFile.fromPath('imagen', imagen.path, filename: 'placa.jpg'));
    }
    final r = await http.Response.fromStream(await req.send().timeout(const Duration(seconds: 60)));
    return _decodificar(r) as Map<String, dynamic>;
  }

  Future<dynamic> _get(String ruta) async {
    final r = await http.get(_url(ruta), headers: _cabeceras).timeout(const Duration(seconds: 60));
    return _decodificar(r);
  }

  dynamic _decodificar(http.Response r) {
    dynamic j;
    try {
      j = jsonDecode(utf8.decode(r.bodyBytes));
    } catch (_) {
      throw ApiError('Respuesta inválida del servidor (HTTP ${r.statusCode})', r.statusCode);
    }
    if (r.statusCode >= 400) {
      final msg = (j is Map && j['message'] != null) ? j['message'].toString() : (j is Map && j['respuesta'] != null ? j['respuesta'].toString() : 'Error HTTP ${r.statusCode}');
      throw ApiError(msg, r.statusCode);
    }
    return j;
  }
}
