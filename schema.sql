-- schema.sql
-- ER図0521修正.pdf に基づく PostgreSQL テーブル定義

CREATE TABLE users (
    user_id SERIAL PRIMARY KEY,
    account_name VARCHAR(50) NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE surveys (
    survey_id SERIAL PRIMARY KEY,
    creator_id INT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    question_key VARCHAR(64) NOT NULL UNIQUE,
    result_key VARCHAR(64) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    is_notified BOOLEAN NOT NULL DEFAULT FALSE,
    survey_spec JSONB NOT NULL,
    start_at TIMESTAMPTZ,
    end_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_surveys_start_at ON surveys(start_at);

CREATE TABLE responses (
    response_id SERIAL PRIMARY KEY,
    survey_id INT NOT NULL REFERENCES surveys(survey_id) ON DELETE CASCADE,
    user_id INT REFERENCES users(user_id) ON DELETE CASCADE,
    answer_data JSONB NOT NULL,
    respondent_age INT,
    respondent_gender INT,
    answered_at TIMESTAMPTZ NOT NULL,
    UNIQUE (survey_id, user_id)
);

CREATE INDEX idx_responses_survey_id ON responses(survey_id);
CREATE INDEX idx_responses_user_id ON responses(user_id);

CREATE TABLE comments (
    comment_id SERIAL PRIMARY KEY,
    survey_id INT NOT NULL REFERENCES surveys(survey_id) ON DELETE CASCADE,
    user_id INT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    content TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_comments_survey_id ON comments(survey_id);
CREATE INDEX idx_comments_user_id ON comments(user_id);

CREATE TABLE likes (
    like_id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    comment_id INT NOT NULL REFERENCES comments(comment_id) ON DELETE CASCADE,
    like_type SMALLINT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_likes_user_id ON likes(user_id);
CREATE INDEX idx_likes_comment_id ON likes(comment_id);
