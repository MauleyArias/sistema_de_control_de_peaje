import 'package:flutter/material.dart';
import 'package:google_sign_in/google_sign_in.dart';

import 'api.dart';
import 'arduino.dart';
import 'config.dart';
import 'pantallas/caseta.dart';
import 'pantallas/consulta.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const PeajeApp());
}

class PeajeApp extends StatelessWidget {
  const PeajeApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Peaje IA',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(colorSchemeSeed: const Color(0xFF1F5EFF), useMaterial3: true),
      home: const Inicio(),
    );
  }
}

class Inicio extends StatefulWidget {
  const Inicio({super.key});

  @override
  State<Inicio> createState() => _InicioState();
}

class _InicioState extends State<Inicio> {
  bool _cargando = true;
  bool _autenticado = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _arrancar();
  }

  Future<void> _arrancar() async {
    try {
      await GoogleSignIn.instance.initialize(serverClientId: Config.googleWebClientId.isEmpty ? null : Config.googleWebClientId);
    } catch (e) {
      _error = 'Google Sign-In no se pudo inicializar: $e';
    }
    final ok = await Api.i.cargarSesion();
    Arduino.i.escucharConexiones();
    Arduino.i.conectar();
    if (!mounted) return;
    setState(() {
      _autenticado = ok;
      _cargando = false;
    });
  }

  Future<void> _entrar() async {
    setState(() {
      _cargando = true;
      _error = null;
    });
    try {
      final cuenta = await GoogleSignIn.instance.authenticate(scopeHint: const ['email']);
      final idToken = cuenta.authentication.idToken;
      if (idToken == null) throw ApiError('Google no devolvió un ID token. Revisa GOOGLE_WEB_CLIENT_ID.');
      await Api.i.loginGoogle(idToken);
      _autenticado = true;
    } on GoogleSignInException catch (e) {
      _error = e.code == GoogleSignInExceptionCode.canceled ? 'Inicio de sesión cancelado.' : 'Google: ${e.description ?? e.code.name}';
    } on ApiError catch (e) {
      // 403 = cuenta fuera de la lista blanca.
      _error = e.status == 403 ? '⛔ Acceso denegado\n${e.mensaje}' : e.mensaje;
      await GoogleSignIn.instance.signOut();
    } catch (e) {
      _error = 'No se pudo iniciar sesión: $e';
    }
    if (mounted) setState(() => _cargando = false);
  }

  Future<void> _salir() async {
    await Api.i.cerrarSesion();
    await GoogleSignIn.instance.signOut();
    setState(() => _autenticado = false);
  }

  @override
  Widget build(BuildContext context) {
    if (_cargando) return const Scaffold(body: Center(child: CircularProgressIndicator()));
    if (_autenticado) return Principal(alSalir: _salir);
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              const Text('🚧', style: TextStyle(fontSize: 56)),
              const SizedBox(height: 8),
              Text('Control de Peaje', style: Theme.of(context).textTheme.headlineSmall),
              const SizedBox(height: 4),
              const Text('Acceso solo con cuentas de Google autorizadas', textAlign: TextAlign.center),
              const SizedBox(height: 24),
              FilledButton.icon(onPressed: _entrar, icon: const Icon(Icons.login), label: const Text('Iniciar sesión con Google')),
              if (_error != null) ...[
                const SizedBox(height: 20),
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(color: Colors.red.shade50, borderRadius: BorderRadius.circular(10)),
                  child: Text(_error!, textAlign: TextAlign.center, style: TextStyle(color: Colors.red.shade800)),
                ),
              ],
              const SizedBox(height: 24),
              Text(Config.apiUrl, style: Theme.of(context).textTheme.bodySmall),
            ]),
          ),
        ),
      ),
    );
  }
}

class Principal extends StatefulWidget {
  const Principal({super.key, required this.alSalir});
  final VoidCallback alSalir;

  @override
  State<Principal> createState() => _PrincipalState();
}

class _PrincipalState extends State<Principal> {
  int _pestana = 0;

  @override
  Widget build(BuildContext context) {
    final u = Api.i.usuario ?? {};
    return Scaffold(
      appBar: AppBar(
        title: Text(_pestana == 0 ? 'Modo Caseta' : 'Modo Consulta'),
        actions: [
          Center(child: Text('${u['email_google'] ?? ''} · ${u['rol'] ?? ''}  ', style: Theme.of(context).textTheme.labelSmall)),
          IconButton(onPressed: widget.alSalir, icon: const Icon(Icons.logout), tooltip: 'Cerrar sesión'),
        ],
      ),
      body: IndexedStack(index: _pestana, children: [ModoCaseta(activa: _pestana == 0), const ModoConsulta()]),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _pestana,
        onDestinationSelected: (i) => setState(() => _pestana = i),
        destinations: const [
          NavigationDestination(icon: Icon(Icons.videocam), label: 'Caseta'),
          NavigationDestination(icon: Icon(Icons.chat), label: 'Consulta'),
        ],
      ),
    );
  }
}
