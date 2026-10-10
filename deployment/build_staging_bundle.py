"""Build private cPanel bundles after npm run build; never includes .env or DB files."""
import argparse
import gzip
import hashlib
import io
import json
import pathlib
import re
import tarfile

root = pathlib.Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser()
parser.add_argument("release", help="Release name, for example 20261006-catalog-03")
parser.add_argument("--environment", choices=["staging", "production"], default="staging")
parser.add_argument("--backend-file-list", type=pathlib.Path, help="Optional JSON list of completed backend files for a partial domain release")
parser.add_argument("--backend-snapshot", type=pathlib.Path, help="Use the immutable backend archive that passed native acceptance")
parser.add_argument("--include-public", action="store_true", help="Package the standalone company website separately from authenticated portals")
args = parser.parse_args()
if not re.fullmatch(r"[a-z0-9][a-z0-9-]{1,63}", args.release):
    raise ValueError("Invalid release name")
output = root / "deployment/.local/bundles" / args.release
output.mkdir(parents=True, exist_ok=False)


def bundle(name, entries):
    target = output / name
    hashes = {}
    with target.open("xb") as file:
        with gzip.GzipFile(fileobj=file, filename="", mode="wb", mtime=0) as compressed:
            with tarfile.open(fileobj=compressed, mode="w") as archive:
                if name == "backend.tar.gz":
                    cache = tarfile.TarInfo("bootstrap/cache")
                    cache.type = tarfile.DIRTYPE
                    cache.mode = 0o700
                    archive.addfile(cache)
                for relative, source in sorted(entries):
                    data = source if isinstance(source, bytes) else source.read_bytes()
                    info = tarfile.TarInfo(relative)
                    info.size = len(data)
                    info.mode = 0o644
                    archive.addfile(info, io.BytesIO(data))
                    hashes[relative] = hashlib.sha256(data).hexdigest()
    return {"sha256": hashlib.sha256(target.read_bytes()).hexdigest(), "bytes": target.stat().st_size, "files": hashes}


backend = root / "backend"
entries = []
for folder in ["app", "bootstrap", "config", "database", "routes", "resources", "public"]:
    for source in (backend / folder).rglob("*"):
        if not source.is_file() or source.is_symlink():
            continue
        relative = source.relative_to(backend).as_posix()
        if relative.startswith("bootstrap/cache/") or source.name.endswith((".sqlite", ".sqlite-wal", ".sqlite-shm")):
            continue
        entries.append((relative, source))
for name in ["artisan", "composer.json", "composer.lock", ".env.example"]:
    entries.append((name, backend / name))
assert "public/index.php" in dict(entries)
if args.backend_snapshot:
    frozen = []
    with tarfile.open(args.backend_snapshot, 'r:gz') as snapshot:
        for member in snapshot:
            if not member.isfile():
                continue
            relative = pathlib.PurePosixPath(member.name)
            if relative.is_absolute() or '..' in relative.parts or str(relative).startswith(('.env', 'storage/', 'vendor/', 'tests/')) and str(relative) != '.env.example':
                raise ValueError('Unsafe backend snapshot entry')
            frozen.append((str(relative), snapshot.extractfile(member).read()))
    if len(dict(frozen)) != len(frozen):
        raise ValueError('Duplicate backend snapshot entries')
    entries = frozen
if args.backend_file_list:
    selected = set(json.loads(args.backend_file_list.read_text(encoding="utf-8")))
    available = dict(entries)
    if not selected.issubset(available) or not {"public/index.php", "artisan", "composer.json", "composer.lock", "routes/web.php"}.issubset(selected):
        raise ValueError("The backend file list contains unavailable files or omits its entry points")
    entries = [(relative, source) for relative, source in entries if relative in selected]
result = {"release": args.release, "environment": args.environment, "backend": bundle("backend.tar.gz", entries)}
if args.backend_snapshot:
    result['backend_native_snapshot_sha256'] = hashlib.sha256(args.backend_snapshot.read_bytes()).hexdigest()
entries = []
bridge_name = "masal-api.php" if args.environment == "production" else "masal-api.staging.php"
bridge = root / "deployment/cpanel" / bridge_name
for portal in ["admin", "agents", "pos"]:
    built = root / f"frontend/dist/{portal}"
    if not (built / "index.html").is_file():
        raise RuntimeError("Build all portals before packaging")
    for source in built.rglob("*"):
        if source.is_file() and not source.is_symlink() and ".vite" not in source.parts:
            entries.append((portal + "/" + source.relative_to(built).as_posix(), source))
    entries.extend([(portal + "/masal-api.php", bridge), (portal + "/.htaccess", root / "deployment/cpanel/portal.htaccess")])
result["portals"] = bundle("portals.tar.gz", entries)
if args.include_public:
    if args.environment != 'production':
        raise ValueError('The public company website is packaged only for production')
    built = root / 'frontend/dist/public'
    if not (built / 'index.html').is_file():
        raise RuntimeError('Build the public company website before packaging')
    entries = [(source.relative_to(built).as_posix(), source) for source in built.rglob('*')
               if source.is_file() and not source.is_symlink() and '.vite' not in source.parts]
    entries.extend([('masal-api.php', bridge), ('.htaccess', root / 'deployment/cpanel/public.htaccess')])
    result['public_site'] = bundle('public-site.tar.gz', entries)
(output / "manifest.json").write_text(json.dumps(result, indent=2), encoding="utf-8")
print(json.dumps({"path": str(output), "release": args.release, "backend_sha256": result["backend"]["sha256"], "portals_sha256": result["portals"]["sha256"]}))
