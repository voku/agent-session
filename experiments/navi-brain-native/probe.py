#!/usr/bin/env python3
"""Read-only upstream integration probe. All memory data is synthetic and disposable."""

from __future__ import annotations

import argparse
import hashlib
import json
import shutil
import socket
import struct
import subprocess
import tempfile
import time
from pathlib import Path
from typing import Any


class ProtocolError(RuntimeError):
    pass


def wire(socket_path: Path, command: str, body: bytes = b"") -> bytes:
    """Issue exactly one TOKMEM/1 request and validate its bounded response."""
    with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as conn:
        conn.settimeout(6.0)
        conn.connect(str(socket_path))
        conn.sendall(f"TOKMEM/1 {command}\n".encode("ascii") + body)
        conn.shutdown(socket.SHUT_WR)
        received = bytearray()
        while True:
            chunk = conn.recv(65536)
            if not chunk:
                break
            received.extend(chunk)
            if len(received) > 8 * 1024 * 1024:
                raise ProtocolError("Response exceeded probe limit")
    header, separator, payload = bytes(received).partition(b"\n")
    if not separator:
        raise ProtocolError(f"Missing response header: {received[:100]!r}")
    parts = header.decode("ascii").split(" ")
    if len(parts) != 2 or parts[0] not in ("OK", "ERR"):
        raise ProtocolError(f"Invalid response header: {header!r}")
    if not parts[1].isdigit() or len(payload) != int(parts[1]):
        raise ProtocolError("Incorrect response payload length")
    if parts[0] == "ERR":
        raise ProtocolError(payload.decode("utf-8", "replace"))
    return payload


def records(data: bytes) -> list[tuple[int, bytes]]:
    """Parse native framed records without depending on PHP or Divergence."""
    lines: list[tuple[int, bytes]] = []
    count_raw, sep, rest = data.partition(b"\n")
    if not sep or not count_raw.isdigit():
        raise ProtocolError("Invalid record count")
    for _ in range(int(count_raw)):
        frame, sep, rest = rest.partition(b"\n")
        if not sep:
            raise ProtocolError("Missing record header")
        sizes = frame.split(b" ")
        if len(sizes) != 2 or not all(size.isdigit() for size in sizes):
            raise ProtocolError("Invalid record sizes")
        meta_len, text_len = map(int, sizes)
        if len(rest) < meta_len + text_len:
            raise ProtocolError("Truncated native record")
        metadata = rest[:meta_len]
        content = rest[meta_len:meta_len + text_len]
        rest = rest[meta_len + text_len:]
        memory_id = int(metadata.split(b"\n", 1)[0])
        lines.append((memory_id, content))
    if rest:
        raise ProtocolError("Unexpected bytes following record batch")
    return lines


def field(value: str) -> str:
    data = value.encode("utf-8")
    return str(len(data)) + (" " + data.hex() if data else "")


def metadata(tier: str = "semantic") -> bytes:
    """Match the upstream PHP TokenMemoryDaemon::encodeMetadata wire ABI."""
    lines = [
        "0", field("2026-11-06 00:00:00"), field("2026-11-06 00:00:00"),
        field(tier), struct.pack(">d", 0.95).hex(), field("active"),
        "-", "-", "-", "-",
    ]
    return ("\n".join(lines) + "\n").encode("ascii")


def create(sock: Path, key: str, text: str) -> tuple[int, bytes]:
    meta = metadata()
    payload = text.encode("utf-8")
    result = records(wire(sock, f"CREATE {key} {len(meta)} {len(payload)}", meta + payload))
    if len(result) != 1:
        raise AssertionError(f"CREATE returned {len(result)} records instead of one")
    return result[0]


def list_all(sock: Path) -> list[tuple[int, bytes]]:
    return records(wire(sock, "LIST NEUTRAL - - id ASC 100"))


def start(binary: Path, store: Path, sock: Path, log: Any) -> subprocess.Popen[bytes]:
    process = subprocess.Popen([str(binary), "daemon", str(store), str(sock)],
                               stdout=log, stderr=subprocess.STDOUT)
    for _ in range(100):
        if process.poll() is not None:
            log.flush()
            detail = Path(log.name).read_text(errors="replace")[-2000:]
            raise RuntimeError(f"Native daemon exited with {process.returncode}: {detail}")
        if sock.exists():
            try:
                wire(sock, "STATS")
                return process
            except (OSError, ProtocolError):
                pass
        time.sleep(0.1)
    raise TimeoutError("Native daemon failed to become ready")


def close(process: subprocess.Popen[bytes], sock: Path) -> None:
    try:
        wire(sock, "FLUSH")
        wire(sock, "CLOSE")
    finally:
        process.wait(timeout=8)
        if process.returncode != 0:
            raise RuntimeError(f"Native daemon returned {process.returncode}")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--binary", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    binary = args.binary.resolve()
    if not binary.is_file():
        raise FileNotFoundError(binary)
    args.output.parent.mkdir(parents=True, exist_ok=True)

    facts = [
        ("AsterCloud", "AsterCloud rollback requires approval from release-oncall."),
        ("CedarFlow", "CedarFlow uses a green deployment gate before promotion."),
        ("OrchidVault", "OrchidVault rotates its synthetic access token every 17 days."),
        ("MapleSync", "MapleSync stores its checkpoint in the staging namespace."),
    ]
    result: dict[str, Any] = {
        "upstream_revision": "39880c39e0d5af2bff5c3ba5f91c0b94aecbbff8",
        "scope": "Native storage correctness and evidence availability, not LLM cognition",
        "cases": [],
        "checks": {},
    }
    try:
        with tempfile.TemporaryDirectory(prefix="navi-brain-probe-") as root:
            base = Path(root)
            store = base / "store"
            # Upstream requires an initialized catalog; a plain directory is invalid.
            subprocess.run([str(binary), "init", str(store)], check=True, timeout=15)
            sock = base / "token.sock"
            with (base / "daemon.log").open("wb") as log:
                daemon = start(binary, store, sock, log)
                try:
                    ids: list[int] = []
                    for project, fact in facts:
                        operation_key = hashlib.sha256(f"fixture:{project}".encode()).hexdigest()
                        memory_id, content = create(sock, operation_key, fact)
                        assert content == fact.encode(), "CREATE changed memory content"
                        ids.append(memory_id)
                        replay_id, replay_content = create(sock, operation_key, fact)
                        assert (replay_id, replay_content) == (memory_id, content), \
                            "Idempotent replay created different content or identity"

                    assert len(set(ids)) == len(facts), "Duplicate IDs"
                    assert len(list_all(sock)) == len(facts), "Duplicate records after replay"
                    result["checks"]["exactly_once_create"] = "pass"

                    try:
                        create(sock, hashlib.sha256(b"fixture:AsterCloud").hexdigest(),
                               "AsterCloud rollback now requires NO approval.")
                    except ProtocolError:
                        pass
                    else:
                        raise AssertionError("Reused operation key accepted changed semantics")
                    assert len(list_all(sock)) == len(facts), "Conflict mutated record count"
                    result["checks"]["receipt_conflict_fails_closed"] = "pass"

                    for project, fact in facts:
                        cue = project.encode()
                        response = records(wire(sock, f"RECALL_RECORDS 4 {len(cue)}", cue))
                        matching = [i + 1 for i, (_, body) in enumerate(response)
                                    if body == fact.encode()]
                        result["cases"].append({
                            "cue": project,
                            "no_memory_evidence_count": 0,
                            "raw_transcript_evidence_count": len(facts),
                            "native_recall_count": len(response),
                            "native_target_rank": matching[0] if matching else None,
                            "native_recall_at_3": bool(matching and matching[0] <= 3),
                        })
                finally:
                    close(daemon, sock)

            assert subprocess.run([str(binary), "verify", str(store)],
                                  capture_output=True, timeout=15).returncode == 0, \
                "Integrity verification failed on clean store"
            result["checks"]["clean_integrity"] = "pass"

            # A new process must see the same records, without importing a transcript.
            with (base / "daemon-restarted.log").open("wb") as log:
                daemon = start(binary, store, sock, log)
                try:
                    reloaded = list_all(sock)
                    assert len(reloaded) == len(facts), "Restart lost or duplicated memory"
                    assert sorted(body.decode() for _, body in reloaded) == \
                        sorted(fact for _, fact in facts), "Restart changed memory bytes"
                    operation_key = hashlib.sha256(b"fixture:AsterCloud").hexdigest()
                    assert create(sock, operation_key, facts[0][1])[0] == ids[0], \
                        "Cross-process idempotency failed"
                    result["checks"]["restart_and_receipt_replay"] = "pass"
                finally:
                    close(daemon, sock)

            # Never corrupt or fault-inject into the canonical instance.
            corrupted = base / "corrupted-copy"
            shutil.copytree(store, corrupted)
            blobs = list((corrupted / "memories").glob("*/*/content.memory"))
            assert blobs, "Native memory store did not write content blobs"
            blob = blobs[0]
            data = blob.read_bytes()
            assert data, "Empty memory blob"
            blob.write_bytes(bytes([data[0] ^ 0xFF]) + data[1:])
            check = subprocess.run([str(binary), "verify", str(corrupted)],
                                   capture_output=True, timeout=15)
            assert check.returncode != 0, "Corruption silently passed verification"
            result["checks"]["corrupted_copy_rejected"] = "pass"

            result["native_recall_at_3"] = sum(
                int(case["native_recall_at_3"]) for case in result["cases"]
            ) / len(facts)
            result["status"] = "pass"
    except Exception as exc:
        result["status"] = "fail"
        result["error"] = f"{type(exc).__name__}: {exc}"
        raise
    finally:
        args.output.write_text(json.dumps(result, indent=2, sort_keys=True) + "\n")
        print(json.dumps(result, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
