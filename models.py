"""
Societies — Pydantic request models (validation des inputs)
"""
from pydantic import BaseModel, Field
from typing import Optional, List, Any


class GenerateRequest(BaseModel):
    title: str = Field(..., min_length=1, max_length=300)
    category: Optional[str] = Field(None, max_length=200)
    city: Optional[str] = Field(None, max_length=100)
    zip_code: Optional[str] = Field(None, max_length=10)
    rating_value: Optional[float] = Field(None, ge=0, le=5)
    rating_votes: Optional[int] = Field(None, ge=0)


class CompanyItem(BaseModel):
    title: str = Field(..., min_length=1, max_length=300)
    category: Optional[str] = Field(None, max_length=200)
    city: Optional[str] = Field(None, max_length=100)
    zip_code: Optional[str] = Field(None, max_length=10)
    rating_value: Optional[float] = Field(None, ge=0, le=5)
    rating_votes: Optional[int] = Field(None, ge=0)


class BatchRequest(BaseModel):
    companies: List[CompanyItem] = Field(..., min_length=1)
    concurrency: int = Field(default=6, ge=1, le=20)
    max: int = Field(default=50, ge=1, le=200)


class AutoStartRequest(BaseModel):
    concurrency: int = Field(default=6, ge=1, le=20)
    batch_size: int = Field(default=50, ge=10, le=200)
    resume_offset: int = Field(default=0, ge=0)


class UpdateFicheRequest(BaseModel):
    qa_answered: Optional[List[Any]] = None
    intro_text: Optional[str] = None
    open_answers: Optional[List[Any]] = None


class LicenseVerifyRequest(BaseModel):
    key: str = Field(..., min_length=1, max_length=100)
