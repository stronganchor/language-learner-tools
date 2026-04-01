cmake_minimum_required(VERSION 3.18)

project(lltools_offline_stt)

set(CMAKE_CXX_STANDARD 17)

# Keep the vendored path short so Git for Windows can stage the tracked JNI tree
# during release/version-bump flows without tripping the MAX_PATH limit.
set(WHISPER_VENDOR_DIR ${CMAKE_SOURCE_DIR}/w)

set(BUILD_SHARED_LIBS OFF CACHE BOOL "Build vendored whisper.cpp deps as static libraries" FORCE)
set(GGML_NATIVE OFF CACHE BOOL "Do not optimize for the build host" FORCE)
set(GGML_CCACHE OFF CACHE BOOL "Disable ccache in app builds" FORCE)
set(GGML_BACKEND_DL OFF CACHE BOOL "Disable dynamic backend loading" FORCE)
set(GGML_BLAS OFF CACHE BOOL "Disable BLAS" FORCE)
set(GGML_CPU ON CACHE BOOL "Enable CPU backend" FORCE)
set(GGML_ACCELERATE OFF CACHE BOOL "Disable Accelerate" FORCE)
set(GGML_LTO OFF CACHE BOOL "Disable LTO for app builds" FORCE)
set(GGML_OPENMP OFF CACHE BOOL "Disable OpenMP" FORCE)
set(GGML_METAL OFF CACHE BOOL "Disable Metal" FORCE)
set(GGML_VULKAN OFF CACHE BOOL "Disable Vulkan" FORCE)
set(GGML_CUDA OFF CACHE BOOL "Disable CUDA" FORCE)
set(GGML_HIP OFF CACHE BOOL "Disable HIP" FORCE)
set(GGML_MUSA OFF CACHE BOOL "Disable MUSA" FORCE)
set(GGML_SYCL OFF CACHE BOOL "Disable SYCL" FORCE)
set(GGML_RPC OFF CACHE BOOL "Disable RPC" FORCE)

add_subdirectory(${WHISPER_VENDOR_DIR}/ggml ${CMAKE_BINARY_DIR}/ggml EXCLUDE_FROM_ALL)

add_library(
    lltools_whisper
    SHARED
    ${WHISPER_VENDOR_DIR}/src/whisper.cpp
    ${CMAKE_SOURCE_DIR}/lltools_whisper_jni.c
)

find_library(ANDROID_LIB android)
find_library(LOG_LIB log)

target_compile_definitions(
    lltools_whisper
    PRIVATE
    GGML_USE_CPU
    WHISPER_VERSION="android-bundled"
)

target_include_directories(
    lltools_whisper
    PRIVATE
    ${WHISPER_VENDOR_DIR}
    ${WHISPER_VENDOR_DIR}/include
    ${WHISPER_VENDOR_DIR}/src
    ${WHISPER_VENDOR_DIR}/ggml/include
    ${WHISPER_VENDOR_DIR}/ggml/src
    ${WHISPER_VENDOR_DIR}/ggml/src/ggml-cpu
)

target_link_libraries(
    lltools_whisper
    PRIVATE
    ${ANDROID_LIB}
    ${LOG_LIB}
    ggml
)
