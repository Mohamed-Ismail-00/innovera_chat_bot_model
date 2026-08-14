"""
LLM Service
Handles communication with the LLM provider (Groq Cloud API or local Ollama).
Supports automatic fallback models to guarantee 100% uptime with zero lag.
Features:
 - Per-model connection timeout (15s connect, 30s read)
 - Proper httpx.ConnectError / httpx.TimeoutException handling
 - Yields nothing (raises RuntimeError) if ALL models fail so RAG fallback triggers
"""

import json
import logging
import httpx
from typing import AsyncGenerator
from app.config import settings

logger = logging.getLogger(__name__)

# Shared timeout configuration
_CONNECT_TIMEOUT = 15.0   # Max wait to establish TCP connection
_READ_TIMEOUT = 30.0      # Max wait between streamed chunks
_POOL_TIMEOUT = 10.0      # Max wait for a connection from the pool


class LLMService:
    """
    Unified LLM service that supports both Groq (cloud) and Ollama (local).
    Features automatic fallback model chain across all active Groq models.
    """

    def __init__(self):
        self.provider = settings.llm_provider  # "groq" or "ollama"

        if self.provider == "groq":
            self.api_key = settings.groq_api_key
            self.base_url = "https://api.groq.com/openai/v1"
            self.primary_model = settings.groq_model
            # Always try llama-3.1-8b-instant first, then llama3-8b-8192, then llama-3.3-70b-versatile
            self.fallback_models = [
                "llama-3.1-8b-instant",
                "llama3-8b-8192",
                "llama-3.3-70b-versatile",
            ]
            logger.info(
                f"LLMService initialized with Groq Cloud. Primary: {self.primary_model}, Fallbacks: {self.fallback_models}"
            )
        else:
            self.base_url = settings.ollama_base_url
            self.model = settings.ollama_model
            logger.info(f"LLMService initialized with Ollama Local. Model: {self.model}")

    async def generate_response_stream(
        self,
        prompt: str,
        system_message: str,
        history: list[dict] | None = None,
    ) -> AsyncGenerator[str, None]:
        """
        Sends a prompt and system message to the configured LLM provider
        and yields the response incrementally.
        """
        if self.provider == "groq":
            # Build list of models to try in sequence without duplicates
            models_to_try = []
            for m in [self.primary_model] + self.fallback_models:
                if m not in models_to_try:
                    models_to_try.append(m)

            success = False
            for model_name in models_to_try:
                logger.info(f"Attempting response using Groq model: {model_name}")
                received_any_chunk = False

                try:
                    async for chunk in self._stream_groq_single_model(
                        prompt, system_message, history, model_name=model_name
                    ):
                        received_any_chunk = True
                        yield chunk

                    if received_any_chunk:
                        success = True
                        break
                except (httpx.ConnectError, httpx.ConnectTimeout) as e:
                    logger.warning(f"Groq connection failed for '{model_name}': {e}. Trying fallback...")
                    continue
                except httpx.TimeoutException as e:
                    logger.warning(f"Groq timeout for '{model_name}': {e}. Trying fallback...")
                    continue
                except httpx.HTTPStatusError as e:
                    status = e.response.status_code if e.response else 0
                    logger.warning(f"Groq model '{model_name}' HTTP {status}: {e}. Trying fallback...")
                    # Don't retry on 401 (bad API key) — it will fail for all models
                    if status == 401:
                        break
                    continue
                except Exception as e:
                    logger.warning(f"Groq Model '{model_name}' unexpected error: {e}. Trying fallback...")
                    continue

            if not success:
                logger.error("All Groq models failed. Triggering Direct Knowledge Fallback...")
                raise RuntimeError("ALL_LLM_MODELS_FAILED")
        else:
            async for chunk in self._stream_ollama(prompt, system_message, history):
                yield chunk

    # ──────────────────────────────────────────────
    #  Groq Cloud API Single Model Streamer
    # ──────────────────────────────────────────────
    async def _stream_groq_single_model(
        self,
        prompt: str,
        system_message: str,
        history: list[dict] | None = None,
        model_name: str = "llama-3.1-8b-instant",
    ) -> AsyncGenerator[str, None]:
        """Stream response from a specific Groq model."""

        messages = [{"role": "system", "content": system_message}]

        if history:
            messages.extend(history)

        messages.append({"role": "user", "content": prompt})

        payload = {
            "model": model_name,
            "messages": messages,
            "stream": True,
            "temperature": 0.3,
            "max_tokens": 1200,
        }

        headers = {
            "Authorization": f"Bearer {self.api_key}",
            "Content-Type": "application/json",
        }

        url = f"{self.base_url}/chat/completions"

        timeout = httpx.Timeout(
            connect=_CONNECT_TIMEOUT,
            read=_READ_TIMEOUT,
            write=10.0,
            pool=_POOL_TIMEOUT,
        )

        async with httpx.AsyncClient(timeout=timeout) as client:
            async with client.stream("POST", url, json=payload, headers=headers) as response:
                if response.status_code != 200:
                    await response.aread()
                    logger.error(
                        f"Groq API model '{model_name}' status error: {response.status_code} - {response.text}"
                    )
                    raise httpx.HTTPStatusError(
                        f"Status {response.status_code}",
                        request=response.request,
                        response=response,
                    )

                async for line in response.aiter_lines():
                    if not line or not line.startswith("data: "):
                        continue
                    data_str = line[6:]
                    if data_str.strip() == "[DONE]":
                        break
                    try:
                        data = json.loads(data_str)
                        delta = data.get("choices", [{}])[0].get("delta", {})
                        content = delta.get("content", "")
                        if content:
                            yield content
                    except json.JSONDecodeError:
                        continue

    # ──────────────────────────────────────────────
    #  Ollama Local API
    # ──────────────────────────────────────────────
    async def _stream_ollama(
        self,
        prompt: str,
        system_message: str,
        history: list[dict] | None = None,
    ) -> AsyncGenerator[str, None]:
        """Stream response from local Ollama instance."""

        messages = [{"role": "system", "content": system_message}]

        if history:
            messages.extend(history)

        messages.append({"role": "user", "content": prompt})

        payload = {
            "model": self.model,
            "messages": messages,
            "stream": True,
        }

        url = f"{self.base_url}/api/chat"

        try:
            async with httpx.AsyncClient(timeout=120.0) as client:
                async with client.stream("POST", url, json=payload) as response:
                    response.raise_for_status()
                    async for chunk in response.aiter_lines():
                        if not chunk:
                            continue
                        try:
                            data = json.loads(chunk)
                            if "message" in data and "content" in data["message"]:
                                yield data["message"]["content"]
                        except json.JSONDecodeError:
                            continue
        except Exception as e:
            logger.exception("Error during Ollama generation")
            # Raise to trigger Direct Knowledge Fallback in rag_service.py
            raise RuntimeError(f"OLLAMA_FAILED: {e}")
